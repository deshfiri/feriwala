<?php

namespace App\Domain\Account\Enums;

use App\Support\StateMachine\TransitionableState;

/**
 * The twenty-two account statuses from requirements.txt §5.3.
 *
 * These are **states of one account**, not account types (§5, §44). Feriwala has
 * a single account structure: there is no customer account, no partner account,
 * and no conversion between them. Everything that looks like a "type" is either
 * a status here, a package entitlement, or a role.
 *
 * The transition map encodes the activation funnel of §5.1 — register, verify,
 * submit KYC, choose a package, pay, be verified, be approved — plus the ways an
 * active account can be restricted and restored. Anything not listed is refused,
 * which is what stops an account reaching Active without KYC approval.
 */
enum AccountStatus: string implements TransitionableState
{
    // Registration and verification
    case Registered = 'registered';
    case MobileVerificationPending = 'mobile_verification_pending';
    case EmailVerificationPending = 'email_verification_pending';

    // KYC (§7)
    case KycPending = 'kyc_pending';
    case KycSubmitted = 'kyc_submitted';
    case KycUnderReview = 'kyc_under_review';
    case KycResubmissionRequired = 'kyc_resubmission_required';
    case KycApproved = 'kyc_approved';
    case KycRejected = 'kyc_rejected';

    // Package and payment (§8, §9)
    case PackageSelectionPending = 'package_selection_pending';
    case PaymentPending = 'payment_pending';
    case PaymentVerificationPending = 'payment_verification_pending';
    case ApprovalPending = 'approval_pending';

    // Operating
    case Active = 'active';
    case PackageRenewalDue = 'package_renewal_due';
    case PackageExpired = 'package_expired';
    case LowWalletBalance = 'low_wallet_balance';
    case WalletTopupRequired = 'wallet_topup_required';

    // Restricted
    case TemporarilyRestricted = 'temporarily_restricted';
    case TemporarilyDisabled = 'temporarily_disabled';
    case Suspended = 'suspended';
    case Closed = 'closed';

    /**
     * @return array<int, self>
     */
    public function transitionsTo(): array
    {
        return match ($this) {
            self::Registered => [
                self::MobileVerificationPending,
                self::EmailVerificationPending,
                self::KycPending,
                self::Closed,
            ],

            self::MobileVerificationPending => [
                self::EmailVerificationPending,
                self::KycPending,
                self::Closed,
            ],

            self::EmailVerificationPending => [
                self::KycPending,
                self::Closed,
            ],

            self::KycPending => [
                self::KycSubmitted,
                self::Closed,
            ],

            self::KycSubmitted => [
                self::KycUnderReview,
                self::Closed,
            ],

            // A reviewer may approve, reject, or ask for corrections (§7.3).
            // Closed is here because closing is an account action, not a review
            // outcome — a user must be able to leave while their KYC sits in a
            // queue, rather than being held until someone gets to it.
            self::KycUnderReview => [
                self::KycApproved,
                self::KycRejected,
                self::KycResubmissionRequired,
                self::Closed,
            ],

            self::KycResubmissionRequired => [
                self::KycSubmitted,
                self::KycRejected,
                self::Closed,
            ],

            // Rejection is not the end — §7.3 allows resubmission to be requested.
            self::KycRejected => [
                self::KycResubmissionRequired,
                self::Closed,
            ],

            self::KycApproved => [
                self::PackageSelectionPending,
                self::Closed,
            ],

            self::PackageSelectionPending => [
                self::PaymentPending,
                self::Closed,
            ],

            // A user may go back and choose a different package before paying.
            self::PaymentPending => [
                self::PaymentVerificationPending,
                self::PackageSelectionPending,
                self::Closed,
            ],

            // A failed or cancelled payment returns to payment, not forward.
            self::PaymentVerificationPending => [
                self::ApprovalPending,
                self::PaymentPending,
                self::Closed,
            ],

            // Activation needs payment verified, KYC approved, and administrative
            // approval (§5.1, §44). This is the only route into Active.
            self::ApprovalPending => [
                self::Active,
                self::KycResubmissionRequired,
                self::Suspended,
                self::Closed,
            ],

            self::Active => [
                self::PackageRenewalDue,
                self::PackageExpired,
                self::LowWalletBalance,
                self::WalletTopupRequired,
                self::TemporarilyRestricted,
                self::TemporarilyDisabled,
                self::Suspended,
                self::Closed,
            ],

            self::PackageRenewalDue => [
                self::Active,
                self::PackageExpired,
                self::PaymentPending,
                self::Closed,
            ],

            // §8.4: features are restored after successful renewal verification.
            self::PackageExpired => [
                self::PaymentPending,
                self::Active,
                self::TemporarilyDisabled,
                self::Closed,
            ],

            // §24.3: services are restored after sufficient top-up.
            self::LowWalletBalance => [
                self::Active,
                self::WalletTopupRequired,
                self::TemporarilyRestricted,
                self::Closed,
            ],

            self::WalletTopupRequired => [
                self::Active,
                self::LowWalletBalance,
                self::TemporarilyRestricted,
                self::TemporarilyDisabled,
                self::Closed,
            ],

            self::TemporarilyRestricted => [
                self::Active,
                self::TemporarilyDisabled,
                self::Suspended,
                self::Closed,
            ],

            self::TemporarilyDisabled => [
                self::Active,
                self::Suspended,
                self::Closed,
            ],

            self::Suspended => [
                self::Active,
                self::Closed,
            ],

            // D18: a closed account is preserved read-only, never deleted, and
            // never reopened — a new relationship is a new account.
            self::Closed => [],
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Closed;
    }

    public function label(): string
    {
        return match ($this) {
            self::Registered => 'Registered',
            self::MobileVerificationPending => 'Mobile verification pending',
            self::EmailVerificationPending => 'Email verification pending',
            self::KycPending => 'KYC pending',
            self::KycSubmitted => 'KYC submitted',
            self::KycUnderReview => 'KYC under review',
            self::KycResubmissionRequired => 'KYC resubmission required',
            self::KycApproved => 'KYC approved',
            self::KycRejected => 'KYC rejected',
            self::PackageSelectionPending => 'Package selection pending',
            self::PaymentPending => 'Payment pending',
            self::PaymentVerificationPending => 'Payment verification pending',
            self::ApprovalPending => 'Approval pending',
            self::Active => 'Active',
            self::PackageRenewalDue => 'Package renewal due',
            self::PackageExpired => 'Package expired',
            self::LowWalletBalance => 'Low wallet balance',
            self::WalletTopupRequired => 'Wallet top-up required',
            self::TemporarilyRestricted => 'Temporarily restricted',
            self::TemporarilyDisabled => 'Temporarily disabled',
            self::Suspended => 'Suspended',
            self::Closed => 'Closed',
        };
    }

    /**
     * Status tone for the interface. Matches the five tones in `lib/status.ts`.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Active, self::KycApproved => 'success',

            self::KycRejected, self::Suspended,
            self::TemporarilyDisabled, self::PackageExpired => 'danger',

            self::KycResubmissionRequired, self::PaymentPending,
            self::PackageRenewalDue, self::LowWalletBalance,
            self::WalletTopupRequired, self::TemporarilyRestricted => 'warning',

            self::KycUnderReview, self::KycSubmitted,
            self::PaymentVerificationPending, self::ApprovalPending => 'info',

            default => 'neutral',
        };
    }

    /**
     * Whether the account has completed activation (§5.1).
     */
    public function isActivated(): bool
    {
        return in_array($this, [
            self::Active,
            self::PackageRenewalDue,
            self::PackageExpired,
            self::LowWalletBalance,
            self::WalletTopupRequired,
            self::TemporarilyRestricted,
        ], true);
    }

    /**
     * Whether the account is still working through the activation funnel.
     */
    public function isOnboarding(): bool
    {
        return ! $this->isActivated()
            && ! in_array($this, [self::TemporarilyDisabled, self::Suspended, self::Closed], true);
    }

    /**
     * Whether the account may use the business features of its package.
     *
     * Deliberately narrower than {@see isActivated()}: an account that has
     * fallen below its minimum wallet balance keeps its data and its dashboard
     * but stops being able to trade (§24.3).
     */
    public function canTransact(): bool
    {
        return in_array($this, [
            self::Active,
            self::PackageRenewalDue,
            self::LowWalletBalance,
        ], true);
    }

    /**
     * Before activation a user reaches only the seven areas listed in §5.4:
     * profile, KYC, package selection, payment, activation status, support, and
     * activation notifications.
     */
    public function isRestrictedToOnboarding(): bool
    {
        return $this->isOnboarding();
    }
}
