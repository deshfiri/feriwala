<?php

namespace App\Domain\Account;

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Models\Payment;
use App\Domain\Kyc\Enums\KycStatus;
use App\Domain\Kyc\Models\KycSubmission;
use App\Models\User;

/**
 * Checks the three conditions §5.1 and §44 require before activation.
 *
 * Payment verification, KYC approval, and administrative approval — all three,
 * every time. They are gathered here rather than checked inline so the same
 * answer serves the approval queue, the activation action, and the user's own
 * onboarding stepper. A queue that shows an account as ready while the action
 * refuses it is worse than either being wrong alone.
 */
class ActivationRequirements
{
    /**
     * Reasons this account cannot yet be activated. Empty means it can.
     *
     * @return array<int, string>
     */
    public function unmet(User $user): array
    {
        $reasons = [];

        if ($user->isActivated()) {
            return ['This account is already active.'];
        }

        if ($user->status === AccountStatus::Closed || $user->status === AccountStatus::Suspended) {
            return ['This account is '.$user->status->label().'.'];
        }

        if (! $this->kycApproved($user)) {
            $reasons[] = 'KYC has not been approved.';
        }

        if (! $this->activationPaid($user)) {
            $reasons[] = 'The activation payment has not been verified.';
        }

        if (! $user->isVerified()) {
            $reasons[] = 'Email and mobile are not both verified.';
        }

        return $reasons;
    }

    public function areMet(User $user): bool
    {
        return $this->unmet($user) === [];
    }

    /**
     * §5.1: KYC approval is a precondition, not a formality.
     */
    public function kycApproved(User $user): bool
    {
        return KycSubmission::query()
            ->where('user_id', $user->id)
            ->where('status', KycStatus::Approved)
            ->exists();
    }

    /**
     * A settled activation payment must exist.
     *
     * Checks the payment's own status rather than the account's, because the
     * account status is what this decides — reading it here would be circular.
     */
    public function activationPaid(User $user): bool
    {
        return Payment::query()
            ->where('user_id', $user->id)
            ->where('purpose', PaymentPurpose::Activation)
            ->settled()
            ->exists();
    }
}
