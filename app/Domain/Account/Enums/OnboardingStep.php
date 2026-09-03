<?php

namespace App\Domain\Account\Enums;

/**
 * The six steps of the activation stepper (§33.4).
 *
 * Deliberately fewer than the 22 account statuses. A user does not need to know
 * the difference between "KYC submitted" and "KYC under review" — both mean
 * "we have your documents, wait". Statuses drive the system; these drive what a
 * person sees.
 */
enum OnboardingStep: string
{
    case Registration = 'registration';
    case Verification = 'verification';
    case Kyc = 'kyc';
    case Package = 'package';
    case Payment = 'payment';
    case Approval = 'approval';

    public function label(): string
    {
        return match ($this) {
            self::Registration => 'Register',
            self::Verification => 'Verify',
            self::Kyc => 'KYC',
            self::Package => 'Package',
            self::Payment => 'Payment',
            self::Approval => 'Approval',
        };
    }

    /**
     * Position in the sequence, from 1.
     */
    public function position(): int
    {
        return match ($this) {
            self::Registration => 1,
            self::Verification => 2,
            self::Kyc => 3,
            self::Package => 4,
            self::Payment => 5,
            self::Approval => 6,
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
     * The step an account status belongs to.
     */
    public static function forStatus(AccountStatus $status): self
    {
        return match ($status) {
            AccountStatus::Registered => self::Registration,

            AccountStatus::MobileVerificationPending,
            AccountStatus::EmailVerificationPending => self::Verification,

            AccountStatus::KycPending,
            AccountStatus::KycSubmitted,
            AccountStatus::KycUnderReview,
            AccountStatus::KycResubmissionRequired,
            AccountStatus::KycRejected => self::Kyc,

            AccountStatus::KycApproved,
            AccountStatus::PackageSelectionPending => self::Package,

            AccountStatus::PaymentPending,
            AccountStatus::PaymentVerificationPending => self::Payment,

            default => self::Approval,
        };
    }
}
