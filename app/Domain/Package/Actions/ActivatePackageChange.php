<?php

namespace App\Domain\Package\Actions;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Package\Enums\SubscriptionSource;
use App\Domain\Package\Enums\UserPackageStatus;
use App\Domain\Package\Models\UserPackage;
use App\Notifications\Package\PackageChanged;
use Illuminate\Database\DatabaseManager;

/**
 * Brings a paid-for package change to life (§8.3).
 *
 * Reached from payment settlement, so an unpaid change never moves an account
 * between plans — in either direction. That matters more for a downgrade than
 * it looks: a limit taken away by an invoice nobody paid would be a restriction
 * imposed for free.
 *
 * The dates were fixed when the change was opened and are used as they stand.
 * Recomputing them here would move the effective date by however long the
 * payment took, which is a different agreement from the one that was quoted.
 *
 * **An upgrade ends the term it replaces; a downgrade does not.** An upgrade
 * applies at once, so the old term is over — it is closed as cancelled, which
 * is what ending a term before its expiry is. A downgrade begins where the old
 * term ends, so the old term keeps running and the scheduled sweep closes it on
 * its own expiry (§8.4).
 *
 * Idempotent and locked: a repeated gateway callback finds the change already
 * live and does nothing.
 */
class ActivatePackageChange
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    public function handle(UserPackage $change): UserPackage
    {
        $activated = $this->database->transaction(function () use ($change) {
            /** @var UserPackage $locked */
            $locked = UserPackage::query()->lockForUpdate()->findOrFail($change->id);

            if ($locked->status !== UserPackageStatus::PendingPayment) {
                return $locked;
            }

            $previous = $locked->renews_user_package_id === null
                ? null
                : UserPackage::query()->lockForUpdate()->find($locked->renews_user_package_id);

            $locked->transitionTo(UserPackageStatus::Active)->save();

            if ($locked->source === SubscriptionSource::Upgrade) {
                $this->closeReplacedTerm($previous);
            }

            $this->repoint($locked);

            return $locked;
        });

        if ($activated->wasChanged()) {
            $this->announce($activated);
        }

        return $activated;
    }

    /**
     * End the term an upgrade replaced.
     *
     * Cancelled rather than expired, because it did not run out — it was ended
     * early by a change the account asked for. The status map allows exactly
     * that move, and going through it rather than assigning is what keeps the
     * history honest.
     */
    protected function closeReplacedTerm(?UserPackage $previous): void
    {
        if ($previous === null || ! $previous->canTransitionTo(UserPackageStatus::Cancelled)) {
            return;
        }

        $previous->transitionTo(UserPackageStatus::Cancelled);
        $previous->forceFill(['cancelled_at' => now()])->save();
    }

    /**
     * Point the account at the term it is now on.
     *
     * Immediately, in both directions. A downgrade that has not started yet
     * does not entitle, and `Entitlements` falls back to whichever term does —
     * so the still-running larger term keeps granting until its own expiry,
     * which is precisely what "effective at the end of the term" means.
     */
    protected function repoint(UserPackage $change): void
    {
        $account = BusinessAccount::query()->lockForUpdate()->whereKey($change->business_account_id)->first();

        $account?->forceFill(['current_user_package_id' => $change->id])->save();
    }

    protected function announce(UserPackage $change): void
    {
        $this->audit->handle(new AuditEntry(
            action: 'package.'.$change->source->value,
            actorType: 'system',
            auditableType: UserPackage::class,
            auditableId: $change->id,
            after: [
                'package' => $change->terms()?->name,
                'effective_from' => $change->started_at?->toIso8601String(),
                'expires_at' => $change->expires_at?->toIso8601String(),
            ],
            accountId: $change->business_account_id,
            module: 'package',
        ));

        $owner = BusinessAccount::query()->whereKey($change->business_account_id)->first()?->owner;

        $owner?->notify(new PackageChanged(
            package: (string) $change->terms()?->name,
            direction: $change->source,
            effectiveFrom: $change->started_at?->toDayDateTimeString(),
        ));
    }
}
