<?php

namespace App\Domain\Supplier\Enums;

use App\Support\StateMachine\TransitionableState;

/**
 * A Supplier's lifecycle (D25, P13-1).
 *
 * A wholly separate lifecycle from `AccountStatus` — this governs a Supplier
 * identity, never a Client/Partner `BusinessAccount`. Only an **approved**,
 * un-suspended Supplier may submit listing requests or reach the operational
 * Supplier screens; every server-side check asks this, never the navigation.
 */
enum SupplierStatus: string implements TransitionableState
{
    case Draft = 'draft';
    case VerificationPending = 'verification_pending';
    case KycPending = 'kyc_pending';
    case UnderReview = 'under_review';
    case CorrectionRequired = 'correction_required';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Suspended = 'suspended';
    case Closed = 'closed';

    /**
     * @return array<int, self>
     */
    public function transitionsTo(): array
    {
        return match ($this) {
            self::Draft => [self::VerificationPending, self::Closed],
            self::VerificationPending => [self::KycPending, self::Closed],
            self::KycPending => [self::UnderReview, self::Closed],

            self::UnderReview => [
                self::Approved, self::Rejected, self::CorrectionRequired, self::Closed,
            ],

            // Correction is not the end — the applicant resubmits into review.
            self::CorrectionRequired => [self::UnderReview, self::Rejected, self::Closed],

            // A decided rejection may still be reopened for a fresh round,
            // mirroring AccountStatus::KycRejected.
            self::Rejected => [self::CorrectionRequired, self::Closed],

            self::Approved => [self::Suspended, self::Closed],

            // Suspension is reversible, exactly as D22 makes it for accounts.
            self::Suspended => [self::Approved, self::Closed],

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
            self::Draft => 'Draft',
            self::VerificationPending => 'Verification pending',
            self::KycPending => 'KYC pending',
            self::UnderReview => 'Under review',
            self::CorrectionRequired => 'Correction required',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Suspended => 'Suspended',
            self::Closed => 'Closed',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Approved => 'success',
            self::Rejected, self::Suspended, self::Closed => 'danger',
            self::CorrectionRequired => 'warning',
            self::UnderReview, self::VerificationPending, self::KycPending => 'info',
            default => 'neutral',
        };
    }

    /**
     * Whether this Supplier may submit listing requests or reach operational
     * Supplier screens (§18.4-equivalent gate). The one question every
     * server-side guard in this domain asks.
     */
    public function isOperational(): bool
    {
        return $this === self::Approved;
    }
}
