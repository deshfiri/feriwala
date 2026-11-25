<?php

namespace App\Domain\Account\Enums;

use App\Domain\Account\ActivationRequirements;
use App\Domain\Account\Models\BusinessAccount;

/**
 * The steps of the onboarding stepper (§33.4), in the order of D39: register,
 * verify, choose a package, pay, verify identity, personal details, business
 * setup, submit, then verification by Banij.
 *
 * Deliberately fewer than the 22 account statuses. A user does not need to know
 * the difference between "KYC submitted" and "KYC under review" — both mean
 * "we have your documents". Statuses drive the system; these drive what a
 * person sees.
 */
enum OnboardingStep: string
{
    case Registration = 'registration';
    case Verification = 'verification';
    case Package = 'package';
    case Payment = 'payment';
    case Kyc = 'kyc';
    case Details = 'details';
    case Business = 'business';
    case Review = 'review';
    case Approval = 'approval';

    public function label(): string
    {
        return __('onboarding.steps.'.$this->value);
    }

    /**
     * Position in the sequence, from 1.
     */
    public function position(): int
    {
        return match ($this) {
            self::Registration => 1,
            self::Verification => 2,
            self::Package => 3,
            self::Payment => 4,
            self::Kyc => 5,
            self::Details => 6,
            self::Business => 7,
            self::Review => 8,
            self::Approval => 9,
        };
    }

    /**
     * @return array<int, self>
     */
    public static function ordered(): array
    {
        $steps = self::cases();

        usort($steps, fn (self $a, self $b) => $a->position() <=> $b->position());

        return $steps;
    }

    /**
     * The step an account is on.
     *
     * Read from the account rather than its status alone: once the package is
     * paid for and KYC is in, the partner fills in personal details, then the
     * business, then submits — none of which is a status (D39). An account
     * still on the earlier order (KYC before the package, not yet paid) stays on
     * the KYC step until its KYC is approved and package selection opens.
     */
    public static function forAccount(BusinessAccount $account, ActivationRequirements $requirements): self
    {
        $status = $account->status;

        if ($account->isActivated() || $status === AccountStatus::ApprovalPending) {
            return self::Approval;
        }

        $kycLane = in_array($status, [
            AccountStatus::KycSubmitted,
            AccountStatus::KycUnderReview,
            AccountStatus::KycApproved,
        ], true);

        if ($kycLane && $requirements->activationPaid($account)) {
            return match (true) {
                $account->personal_details_completed_at === null => self::Details,
                $account->business_setup_completed_at === null => self::Business,
                ! $account->hasSubmittedApplication() => self::Review,
                default => self::Approval,
            };
        }

        return self::forStatus($status);
    }

    /**
     * The step an account status belongs to, without the account behind it.
     */
    public static function forStatus(AccountStatus $status): self
    {
        return match ($status) {
            AccountStatus::Registered => self::Registration,

            AccountStatus::MobileVerificationPending,
            AccountStatus::EmailVerificationPending => self::Verification,

            AccountStatus::PackageSelectionPending => self::Package,

            AccountStatus::PaymentPending,
            AccountStatus::PaymentVerificationPending => self::Payment,

            AccountStatus::KycPending,
            AccountStatus::KycSubmitted,
            AccountStatus::KycUnderReview,
            AccountStatus::KycResubmissionRequired,
            AccountStatus::KycRejected => self::Kyc,

            // Approved under the earlier order and not yet paid: the package is next.
            AccountStatus::KycApproved => self::Package,

            default => self::Approval,
        };
    }
}
