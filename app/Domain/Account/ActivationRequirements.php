<?php

namespace App\Domain\Account;

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Models\Payment;
use App\Domain\Kyc\Enums\KycStatus;
use App\Domain\Kyc\Models\KycSubmission;

/**
 * Checks the three conditions §5.1 and §44 require before activation.
 *
 * Payment verification, KYC approval, and administrative approval — all three,
 * every time. They are gathered here rather than checked inline so the same
 * answer serves the approval queue, the activation action, and the owner's own
 * onboarding stepper. A queue that shows an account as ready while the action
 * refuses it is worse than either being wrong alone.
 *
 * Asked of the **business account** (D23). Commercial onboarding is completed
 * once, by the owner: an invited staff member has no KYC, no package and no
 * activation payment of their own, and their unverified mobile has nothing to
 * do with whether the business may trade. Verification is therefore read from
 * the owner rather than from whoever happens to be signed in.
 */
class ActivationRequirements
{
    /**
     * Reasons this account cannot yet be activated. Empty means it can.
     *
     * @return array<int, string>
     */
    public function unmet(BusinessAccount $account): array
    {
        $reasons = [];

        if ($account->isActivated()) {
            return ['This account is already active.'];
        }

        if ($account->status === AccountStatus::Closed || $account->status === AccountStatus::Suspended) {
            return ['This account is '.$account->status->label().'.'];
        }

        if (! $this->kycApproved($account)) {
            $reasons[] = 'KYC has not been approved.';
        }

        if (! $this->activationPaid($account)) {
            $reasons[] = 'The activation payment has not been verified.';
        }

        if (! $this->ownerVerified($account)) {
            $reasons[] = 'Email and mobile are not both verified.';
        }

        return $reasons;
    }

    public function areMet(BusinessAccount $account): bool
    {
        return $this->unmet($account) === [];
    }

    /**
     * §5.1: KYC approval is a precondition, not a formality.
     */
    public function kycApproved(BusinessAccount $account): bool
    {
        return KycSubmission::query()
            ->where('business_account_id', $account->id)
            ->where('status', KycStatus::Approved)
            ->exists();
    }

    /**
     * A settled activation payment must exist.
     *
     * Checks the payment's own status rather than the account's, because the
     * account status is what this decides — reading it here would be circular.
     */
    public function activationPaid(BusinessAccount $account): bool
    {
        return Payment::query()
            ->where('business_account_id', $account->id)
            ->where('purpose', PaymentPurpose::Activation)
            ->settled()
            ->exists();
    }

    /**
     * The owner's email and mobile, both verified (§5.1).
     */
    public function ownerVerified(BusinessAccount $account): bool
    {
        return $account->owner?->isVerified() ?? false;
    }
}
