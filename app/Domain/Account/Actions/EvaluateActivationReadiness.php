<?php

namespace App\Domain\Account\Actions;

use App\Domain\Account\ActivationRequirements;
use App\Domain\Account\Data\AccountStatusChange;
use App\Domain\Account\Enums\AccountStatus;
use App\Models\User;
use App\Support\Concurrency\DistributedLock;
use Illuminate\Database\DatabaseManager;

/**
 * Keeps an account's position at the activation gate in step with its
 * requirements (§5.1, §44).
 *
 * Runs whenever anything that feeds {@see ActivationRequirements} moves — KYC
 * approved or withdrawn, an activation payment settled, refunded or reversed, a
 * package chosen or changed. Its job is to make
 * {@see AccountStatus::ApprovalPending} mean what it says, so the approval queue
 * can read one column instead of re-deriving eligibility on every page load.
 *
 * Two directions, both of which matter:
 *
 *   - **Ready** → move to `ApprovalPending` and stamp `approval_pending_at`.
 *     That timestamp is the queue's sort key: it is when the account started
 *     waiting on *us*, which is not when it registered.
 *   - **No longer ready** → a requirement was reversed after the account joined
 *     the queue. It leaves, and the readiness stamp is cleared rather than left
 *     behind to make a later re-entry look older than it is.
 *
 * Idempotent by design: an account already in the right place is left alone, so
 * a payment webhook arriving three times does not produce three status changes.
 * The whole evaluation is serialised per account, because two requirements
 * landing at once would otherwise both see "not ready yet".
 */
class EvaluateActivationReadiness
{
    public function __construct(
        protected ActivationRequirements $requirements,
        protected ChangeAccountStatus $changeStatus,
        protected DatabaseManager $database,
        protected DistributedLock $lock,
    ) {}

    /**
     * @return bool whether the account is at the approval gate afterwards
     */
    public function handle(User $user, ?string $reason = null): bool
    {
        return $this->lock->run(
            key: 'account:readiness:'.$user->id,
            callback: fn () => $this->evaluate($user, $reason),
            ttlSeconds: 15,
            waitSeconds: 10,
        );
    }

    protected function evaluate(User $user, ?string $reason): bool
    {
        return $this->database->transaction(function () use ($user, $reason) {
            /** @var User|null $locked */
            $locked = User::query()->lockForUpdate()->find($user->id);

            if ($locked === null) {
                return false;
            }

            // An activated, suspended or closed account is not a candidate, and
            // the requirements object says so itself — no need to duplicate the
            // list of statuses here.
            $isReady = $this->requirements->areMet($locked);
            $isAtGate = $locked->status === AccountStatus::ApprovalPending;

            if ($isReady && ! $isAtGate) {
                $this->moveToGate($locked, $reason);
                $user->setRawAttributes($locked->getAttributes(), sync: true);

                return true;
            }

            if (! $isReady && $isAtGate) {
                $this->leaveGate($locked);
                $user->setRawAttributes($locked->getAttributes(), sync: true);

                return false;
            }

            // Already in the right place. Stamp a readiness time if an older
            // account reached the gate before this column existed, so it does
            // not sort as though it had been waiting forever.
            if ($isAtGate && $locked->approval_pending_at === null) {
                $locked->forceFill(['approval_pending_at' => now()])->save();
                $user->setRawAttributes($locked->getAttributes(), sync: true);
            }

            return $isAtGate;
        });
    }

    protected function moveToGate(User $user, ?string $reason): void
    {
        // The status machine decides whether this move is legal. An account
        // part-way through onboarding may not be able to jump straight here,
        // and forcing it would destroy the history §5.3 depends on.
        if (! $user->canTransitionTo(AccountStatus::ApprovalPending)) {
            return;
        }

        $this->changeStatus->handle($user, AccountStatusChange::automatic(
            AccountStatus::ApprovalPending,
            $reason ?? 'All activation requirements are met.',
        ));

        $user->forceFill(['approval_pending_at' => now()])->save();
    }

    /**
     * Take an account back out of the queue.
     *
     * Only the readiness stamp is cleared. The account keeps its status until
     * whatever reversed the requirement moves it — a refunded payment and a
     * withdrawn KYC approval belong in different places, and guessing between
     * them here would put accounts in a state nobody chose.
     */
    protected function leaveGate(User $user): void
    {
        $user->forceFill(['approval_pending_at' => null])->save();
    }
}
