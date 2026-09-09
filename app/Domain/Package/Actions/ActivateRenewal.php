<?php

namespace App\Domain\Package\Actions;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Package\Entitlements;
use App\Domain\Package\Enums\UserPackageStatus;
use App\Domain\Package\Models\UserPackage;
use App\Notifications\Package\SubscriptionRenewed;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;

/**
 * Brings a paid-for renewal to life (§8.2, §8.4).
 *
 * The one place a renewal becomes real, reached from payment settlement. §8.4
 * calls this "successful renewal verification", and everything it restores
 * hangs off the money actually having cleared — an unpaid renewal grants
 * nothing, whatever else has happened.
 *
 * **The new term starts where the old one ends, not today.** Renewing a month
 * early would otherwise throw that month away, which turns paying on time into
 * a penalty. Where the old term has already run out, there is nothing left to
 * carry and the new one starts now.
 *
 * Idempotent and locked. A gateway sends the same notification several times,
 * and a renewal activated twice would chain two terms off one payment.
 */
class ActivateRenewal
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    public function handle(UserPackage $renewal): UserPackage
    {
        $activated = $this->database->transaction(function () use ($renewal) {
            /** @var UserPackage $locked */
            $locked = UserPackage::query()->lockForUpdate()->findOrFail($renewal->id);

            // Already live. Returning it rather than re-activating is what keeps
            // a repeated callback free of side effects.
            if ($locked->status !== UserPackageStatus::PendingPayment) {
                return $locked;
            }

            $previous = $locked->renews_user_package_id === null
                ? null
                : UserPackage::query()->lockForUpdate()->find($locked->renews_user_package_id);

            $startedAt = $this->startFor($previous);
            $terms = $locked->terms();

            /*
             * The term's length comes from the snapshot this renewal captured,
             * never from the package as it stands now — an edit made between
             * opening the renewal and the payment clearing must not change the
             * year that was bought (§8.3).
             */
            $validityDays = $terms?->validityDays;
            $graceDays = $terms === null ? 0 : ($terms->gracePeriodDays ?? 0);

            $expiresAt = $validityDays === null ? null : $startedAt->addDays($validityDays);

            $locked->transitionTo(UserPackageStatus::Active);

            $locked->forceFill([
                'started_at' => $startedAt,
                'expires_at' => $expiresAt,
                'grace_ends_at' => $expiresAt?->addDays($graceDays),
            ])->save();

            $this->closePrevious($previous);
            $this->repoint($locked);

            return $locked;
        });

        if ($activated->wasChanged()) {
            $this->announce($activated);
        }

        return $activated;
    }

    /**
     * When the renewed term begins.
     *
     * The moment the previous one ends, so days already paid for are not lost —
     * or now, when the previous term is over, missing, or endless.
     */
    protected function startFor(?UserPackage $previous): CarbonImmutable
    {
        $now = CarbonImmutable::instance(now());

        if ($previous?->expires_at === null) {
            return $now;
        }

        return $previous->expires_at->isFuture() ? $previous->expires_at : $now;
    }

    /**
     * End the term that was renewed.
     *
     * Only once it is actually over. A term renewed early is still running and
     * still entitling; expiring it the moment the renewal is paid would take
     * away the weeks somebody paid for and renewed to protect. The scheduled
     * sweep closes it when its own expiry arrives (§8.4).
     */
    protected function closePrevious(?UserPackage $previous): void
    {
        if ($previous === null || $previous->expires_at?->isFuture()) {
            return;
        }

        if ($previous->canTransitionTo(UserPackageStatus::Expired)) {
            $previous->transitionTo(UserPackageStatus::Expired)->save();
        }
    }

    /**
     * Point the account at the term it is now on (§8.2).
     *
     * Repointed immediately, even when the new term has not started yet.
     * {@see Entitlements::activePackage()} checks that the
     * pointed-at term actually entitles *now* and falls back to whichever one
     * does, so the still-running previous term keeps granting until its own
     * expiry — the pointer says what was bought, the entitlement check says
     * what applies today, and neither has to lie to keep the other right.
     */
    protected function repoint(UserPackage $renewal): void
    {
        $account = BusinessAccount::query()->lockForUpdate()->find($renewal->business_account_id);

        $account?->forceFill(['current_user_package_id' => $renewal->id])->save();
    }

    protected function announce(UserPackage $renewal): void
    {
        $this->audit->handle(new AuditEntry(
            action: 'package.renewed',

            // No person decided this: a settled payment did. Naming an actor
            // would put a human behind a gateway callback.
            actorType: 'system',
            auditableType: UserPackage::class,
            auditableId: $renewal->id,
            after: [
                'package' => $renewal->terms()?->name,
                'started_at' => $renewal->started_at?->toIso8601String(),
                'expires_at' => $renewal->expires_at?->toIso8601String(),
            ],
            accountId: $renewal->business_account_id,
            module: 'package',
        ));

        $owner = BusinessAccount::query()->find($renewal->business_account_id)?->owner;

        $owner?->notify(new SubscriptionRenewed(
            package: (string) $renewal->terms()?->name,
            expiresAt: $renewal->expires_at?->toDayDateTimeString(),
        ));
    }
}
