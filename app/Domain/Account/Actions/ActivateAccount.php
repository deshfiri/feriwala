<?php

namespace App\Domain\Account\Actions;

use App\Domain\Account\ActivationRequirements;
use App\Domain\Account\Data\AccountStatusChange;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Exceptions\ActivationBlocked;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Package\Enums\UserPackageStatus;
use App\Domain\Package\Models\UserPackage;
use App\Models\User;
use App\Notifications\Account\AccountActivated;
use App\Support\Concurrency\DistributedLock;
use Illuminate\Database\DatabaseManager;

/**
 * Activates an account after administrative approval (§5.1, §44).
 *
 * The last gate, and the only route to {@see AccountStatus::Active}. It re-checks
 * every precondition rather than trusting that the approval queue already did —
 * the queue is a view, this is the decision, and the two can drift apart while
 * an approval sits waiting.
 *
 * Everything moves in one transaction: the account status, the subscription
 * becoming active, and the account's pointer to it. A subscription activated
 * without the account, or an account activated with no live subscription, would
 * both leave someone unable to trade while apparently able to.
 */
class ActivateAccount
{
    public function __construct(
        protected ActivationRequirements $requirements,
        protected ChangeAccountStatus $changeStatus,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
        protected DistributedLock $lock,
    ) {}

    /**
     * @param  int  $approvedBy  the administrator taking responsibility
     *
     * @throws ActivationBlocked
     */
    public function handle(User $user, int $approvedBy, ?string $note = null): User
    {
        // Serialised per account. Two reviewers deciding at the same moment must
        // resolve to one outcome, and the requirements re-check below has to
        // happen inside that serialisation to be worth anything.
        $activated = $this->lock->run(
            key: 'account:activation:'.$user->id,
            callback: fn () => $this->activate($user, $approvedBy, $note),
            ttlSeconds: 30,
            waitSeconds: 10,
        );

        // Outside the transaction: a queued mail for a change that then rolled
        // back is worse than a slightly later one.
        $user->notify(new AccountActivated($note));

        return $activated;
    }

    /**
     * @throws ActivationBlocked
     */
    protected function activate(User $user, int $approvedBy, ?string $note): User
    {
        return $this->database->transaction(function () use ($user, $approvedBy, $note) {
            /** @var User $locked */
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);

            // Re-checked under the row lock, not before it. Between the queue
            // rendering and this moment a payment can be refunded or a KYC
            // approval withdrawn, and the queue is a view — this is the decision.
            $unmet = $this->requirements->unmet($locked);

            if ($unmet !== []) {
                throw ActivationBlocked::because($unmet);
            }

            $from = $locked->status;

            // §5.3 routes activation through approval. An account that reached
            // this point another way is moved onto the approved step first, so
            // the history shows the gate rather than skipping it.
            if ($locked->status !== AccountStatus::ApprovalPending
                && $locked->canTransitionTo(AccountStatus::ApprovalPending)) {
                $this->changeStatus->handle($locked, new AccountStatusChange(
                    to: AccountStatus::ApprovalPending,
                    changedBy: $approvedBy,
                    reason: 'All activation conditions met.',
                ));
            }

            $this->changeStatus->handle($locked, new AccountStatusChange(
                to: AccountStatus::Active,
                changedBy: $approvedBy,
                reason: 'Activation approved.',
                userVisibleNote: $note ?? 'Your account is now active.',
            ));

            $this->activateSubscription($locked);

            // The account has left the approval queue. Clearing the stamp keeps
            // the queue's sort key meaning only "waiting for review".
            $locked->forceFill(['approval_pending_at' => null])->save();

            $this->audit->handle(new AuditEntry(
                action: 'account.activated',
                actorId: $approvedBy,
                auditableType: User::class,
                auditableId: $locked->id,
                before: ['status' => $from->value],
                after: ['status' => AccountStatus::Active->value],
                reason: 'Activation approved.',
                note: $note,
                accountId: $locked->id,
                module: 'account',
            ));

            $user->setRawAttributes($locked->getAttributes(), sync: true);

            return $user;
        });
    }

    /**
     * Bring the paid-for subscription to life.
     *
     * Its term starts now rather than at purchase — someone whose approval sat
     * in a queue for three days should not lose three days of the package they
     * paid for.
     */
    protected function activateSubscription(User $user): void
    {
        $subscription = UserPackage::query()
            ->where('user_id', $user->id)
            ->where('status', UserPackageStatus::PendingPayment)
            ->latest('id')
            ->lockForUpdate()
            ->first();

        if ($subscription === null) {
            return;
        }

        $package = $subscription->package;
        $startedAt = now();

        $subscription->forceFill([
            'status' => UserPackageStatus::Active,
            'started_at' => $startedAt,
            'expires_at' => $package?->validity_days === null
                ? null
                : $startedAt->addDays($package->validity_days),
            'grace_ends_at' => $package?->validity_days === null
                ? null
                : $startedAt
                    ->addDays($package->validity_days)
                    ->addDays($package->grace_period_days ?? 0),
        ])->save();

        $user->forceFill(['current_user_package_id' => $subscription->id])->save();
    }
}
