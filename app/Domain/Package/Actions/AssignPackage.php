<?php

namespace App\Domain\Package\Actions;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Package\Data\SubscriptionTerms;
use App\Domain\Package\Enums\SubscriptionSource;
use App\Domain\Package\Enums\UserPackageStatus;
use App\Domain\Package\Models\Package;
use App\Domain\Package\Models\UserPackage;
use App\Models\User;
use App\Notifications\Package\PackageAssigned;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Gives an account a package it did not buy (§8.3).
 *
 * A manual or promotional assignment is **real entitlement and no money**. That
 * is the whole difficulty with it: everything downstream — revenue reports,
 * renewal quotes, refund eligibility — has to be able to tell it from a sale,
 * and the only honest way is to record it as what it is. So there is no payment
 * here, fake or otherwise: {@see SubscriptionSource::isPaid()} answers false for
 * both sources, `paid_fee_minor` is zero, and nothing invents a transaction that
 * never happened.
 *
 * **The dates are given, not derived.** §8.3 lists an effective date among the
 * things an administrator configures, and a granted term is exactly where that
 * matters: a promotion runs for its promotion, not for whatever the package's
 * validity happens to say this month.
 *
 * A reason is required and is not optional wording. Somebody will ask why this
 * account has a plan nobody paid for — during a reconciliation, an audit, or a
 * dispute — and "an administrator assigned it" without a because is not an
 * answer. It goes to the audit trail and is never shown to the account.
 *
 * The status moves through the machine rather than being assigned: a granted
 * term is created awaiting payment and transitioned to active in the same
 * breath, which is the declared route and keeps the one rule that
 * {@see UserPackageStatus} exists to enforce.
 */
class AssignPackage
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    public function handle(
        BusinessAccount $account,
        Package $package,
        User $actor,
        string $reason,
        CarbonImmutable $startsAt,
        ?CarbonImmutable $expiresAt = null,
        bool $promotional = false,
    ): UserPackage {
        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'Assigning a package requires a recorded reason.'
            );
        }

        if ($expiresAt !== null && ! $expiresAt->isAfter($startsAt)) {
            throw new InvalidArgumentException(
                'A granted term must end after it begins.'
            );
        }

        $subscription = $this->database->transaction(function () use (
            $account, $package, $startsAt, $expiresAt, $promotional
        ) {
            /** @var BusinessAccount $locked */
            $locked = BusinessAccount::query()->lockForUpdate()->findOrFail($account->id);

            $terms = SubscriptionTerms::capture($package->load(['features', 'charges']));

            $granted = UserPackage::create([
                'business_account_id' => $locked->id,
                'package_id' => $package->id,
                'status' => UserPackageStatus::PendingPayment,
                'source' => $promotional
                    ? SubscriptionSource::Promotional
                    : SubscriptionSource::Manual,

                // Nothing was paid, and the record says so rather than carrying
                // the package's price as though it had been.
                'paid_fee_minor' => 0,
                'currency_code' => $terms->currencyCode,

                'terms' => $terms->toArray(),
                'terms_captured_at' => now(),

                'started_at' => $startsAt,
                'expires_at' => $expiresAt,
                'grace_ends_at' => $expiresAt?->addDays($terms->gracePeriodDays ?? 0),
            ]);

            // The declared route from awaiting payment to live. Assigning the
            // status directly would skip the one guard the machine exists for.
            $granted->transitionTo(UserPackageStatus::Active)->save();

            $this->closeCurrentTerm($locked, $granted, $startsAt);

            $locked->forceFill(['current_user_package_id' => $granted->id])->save();

            $account->setRawAttributes($locked->getAttributes(), sync: true);

            return $granted;
        });

        $this->record($account, $subscription, $actor, $reason);

        $account->owner?->notify(new PackageAssigned(
            package: (string) $subscription->terms()?->name,
            startsAt: $subscription->started_at?->toDayDateTimeString(),
            expiresAt: $subscription->expires_at?->toDayDateTimeString(),
        ));

        return $subscription;
    }

    /**
     * End the term a grant replaces, when the grant starts now.
     *
     * Cancelled, because it was ended early by a decision rather than by
     * running out — the same reasoning as an upgrade. A grant dated into the
     * future replaces nothing yet, so the current term is left to run and
     * `Entitlements` keeps granting from it until the day arrives.
     */
    protected function closeCurrentTerm(
        BusinessAccount $account,
        UserPackage $granted,
        CarbonImmutable $startsAt,
    ): void {
        if ($startsAt->isFuture()) {
            return;
        }

        $current = $account->packages()
            ->whereKeyNot($granted->id)
            ->whereIn('status', UserPackageStatus::entitling())
            ->lockForUpdate()
            ->get();

        foreach ($current as $term) {
            if ($term->canTransitionTo(UserPackageStatus::Cancelled)) {
                $term->transitionTo(UserPackageStatus::Cancelled);
                $term->forceFill(['cancelled_at' => now()])->save();
            }
        }
    }

    protected function record(
        BusinessAccount $account,
        UserPackage $granted,
        User $actor,
        string $reason,
    ): void {
        $this->audit->handle(new AuditEntry(
            action: 'package.'.$granted->source->value.'_assigned',

            // A person decided this, and the record names them. That is the
            // difference between a grant and a purchase.
            actorId: $actor->id,
            auditableType: UserPackage::class,
            auditableId: $granted->id,
            after: [
                'package' => $granted->terms()?->name,
                'source' => $granted->source->value,
                'started_at' => $granted->started_at?->toIso8601String(),
                'expires_at' => $granted->expires_at?->toIso8601String(),
            ],
            reason: $reason,
            accountId: $account->id,
            module: 'package',
            // Entitlement handed out for nothing is what an investigation goes
            // looking for.
            isSensitive: true,
        ));
    }
}
