<?php

namespace App\Domain\Package\Actions;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Billing\RefundabilityPolicy;
use App\Domain\Package\Enums\UserPackageStatus;
use App\Domain\Package\Models\UserPackage;
use App\Models\User;
use App\Notifications\Package\SubscriptionCancelled;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use RuntimeException;

/**
 * Ends a term before it runs out (§8.2).
 *
 * **Cancelling stops the term now.** `UserPackageStatus::Cancelled` is terminal
 * and grants nothing, and pretending otherwise — leaving access on until the
 * expiry date while calling the term cancelled — would mean the status no longer
 * describes what the account can do, which is the one thing it is for.
 *
 * That makes it a decision with a cost, so the screen says so before the button
 * and the confirmation restates it. **Money already paid is not refunded here.**
 * Refunds are their own workflow with their own eligibility and approval (§27,
 * {@see RefundabilityPolicy}), and quietly issuing one from
 * a cancellation would route money out of the platform through a screen that
 * never asked anybody.
 *
 * A reason is recorded. An account holder cancelling gives one for support to
 * read; an administrator cancelling gives one because it will be questioned.
 * It is never shown back to the account as though Feriwala had written it.
 *
 * The move goes through the status machine, so a term that has already ended,
 * been superseded or been cancelled is refused rather than cancelled twice.
 */
class CancelSubscription
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    public function handle(
        UserPackage $subscription,
        ?User $actor,
        string $reason,
    ): UserPackage {
        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'Cancelling a subscription requires a recorded reason.'
            );
        }

        $cancelled = $this->database->transaction(function () use ($subscription, $actor, $reason) {
            /** @var UserPackage $locked */
            $locked = UserPackage::query()->lockForUpdate()->findOrFail($subscription->id);

            if (! $locked->canTransitionTo(UserPackageStatus::Cancelled)) {
                throw new RuntimeException('That subscription cannot be cancelled.');
            }

            $from = $locked->status;

            $locked->transitionTo(UserPackageStatus::Cancelled);
            $locked->forceFill(['cancelled_at' => now()])->save();

            $this->repointAccount($locked);

            $subscription->setRawAttributes($locked->getAttributes(), sync: true);

            $this->audit->handle(new AuditEntry(
                action: 'package.cancelled',

                // Null when an account holder cancelled their own term — nobody
                // exercised authority over somebody else, and inventing an
                // actor would say otherwise.
                actorId: $actor?->id,
                actorType: $actor === null ? 'system' : 'user',
                auditableType: UserPackage::class,
                auditableId: $locked->id,
                before: ['status' => $from->value],
                after: ['status' => UserPackageStatus::Cancelled->value],
                reason: $reason,
                accountId: $locked->business_account_id,
                module: 'package',
                isSensitive: true,
            ));

            return $locked;
        });

        BusinessAccount::query()
            ->with('owner')
            ->whereKey($cancelled->business_account_id)
            ->first()
            ?->owner
            ?->notify(new SubscriptionCancelled(
                package: (string) $cancelled->terms()?->name,
            ));

        return $cancelled;
    }

    /**
     * Move the pointer off a term that no longer grants anything.
     *
     * Where another term still entitles — a renewal already paid for, a
     * scheduled change — the account moves onto it. Where none does, the
     * pointer is cleared rather than left naming a cancelled term, which is
     * what made a closed term keep presenting itself as current.
     */
    protected function repointAccount(UserPackage $cancelled): void
    {
        $account = BusinessAccount::query()
            ->lockForUpdate()
            ->whereKey($cancelled->business_account_id)->first();

        if ($account === null || $account->current_user_package_id !== $cancelled->id) {
            return;
        }

        $successor = $account->packages()
            ->whereKeyNot($cancelled->id)
            ->whereIn('status', UserPackageStatus::entitling())
            ->latest('id')
            ->first();

        $account->forceFill(['current_user_package_id' => $successor?->id])->save();
    }
}
