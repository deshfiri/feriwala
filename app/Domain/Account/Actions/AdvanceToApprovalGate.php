<?php

namespace App\Domain\Account\Actions;

use App\Domain\Account\Data\AccountStatusChange;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;

/**
 * Walks an account up to {@see AccountStatus::ApprovalPending}, recording every
 * step it takes (§5.1, §5.3).
 *
 * The status machine has no direct route from `PaymentPending` to the gate — the
 * payment has to be *received* before it can be *verified* — and nothing else in
 * the application ever enters `PaymentVerificationPending`. An account whose
 * activation payment settled therefore stayed at "Payment pending" with every
 * requirement met, and approving it threw an illegal transition.
 *
 * This is the one place that takes the intermediate step, so the two callers that
 * need the gate — the readiness evaluation and the approval itself — cannot
 * disagree about how to reach it. The machine is deliberately left as it is: the
 * history keeps showing the payment being received rather than a step skipped.
 *
 * The caller holds the row lock and the surrounding transaction.
 */
class AdvanceToApprovalGate
{
    public function __construct(
        protected ChangeAccountStatus $changeStatus,
    ) {}

    /**
     * @return bool whether the account is at the gate afterwards
     */
    public function handle(BusinessAccount $account, ?int $changedBy, string $reason): bool
    {
        if ($account->status === AccountStatus::ApprovalPending) {
            return true;
        }

        if ($account->status === AccountStatus::PaymentPending
            && $account->canTransitionTo(AccountStatus::PaymentVerificationPending)) {
            $this->changeStatus->handle($account, new AccountStatusChange(
                to: AccountStatus::PaymentVerificationPending,
                changedBy: $changedBy,
                reason: 'Activation payment received.',
            ));
        }

        // An account part-way through onboarding may not be able to jump
        // straight here, and forcing it would destroy the history §5.3
        // depends on.
        if (! $account->canTransitionTo(AccountStatus::ApprovalPending)) {
            return false;
        }

        $this->changeStatus->handle($account, new AccountStatusChange(
            to: AccountStatus::ApprovalPending,
            changedBy: $changedBy,
            reason: $reason,
        ));

        return true;
    }
}
