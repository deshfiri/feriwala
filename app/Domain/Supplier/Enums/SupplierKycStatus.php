<?php

namespace App\Domain\Supplier\Enums;

use App\Support\StateMachine\TransitionableState;

/**
 * One round of Supplier KYC (D25, P13-7). Mirrors the shape of
 * `App\Domain\Kyc\Enums\KycStatus` on purpose — the same "draft, submit,
 * review, decide" shape the Client/Partner engine already established — while
 * remaining its own table and its own enum.
 */
enum SupplierKycStatus: string implements TransitionableState
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case CorrectionRequired = 'correction_required';
    case Approved = 'approved';
    case Rejected = 'rejected';

    /**
     * @return array<int, self>
     */
    public function transitionsTo(): array
    {
        return match ($this) {
            self::Draft => [self::Submitted],
            self::Submitted => [self::UnderReview],
            self::UnderReview => [self::Approved, self::Rejected, self::CorrectionRequired],
            self::CorrectionRequired => [self::Submitted],
            self::Approved, self::Rejected => [],
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Approved, self::Rejected], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Submitted',
            self::UnderReview => 'Under review',
            self::CorrectionRequired => 'Correction required',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
        };
    }

    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::CorrectionRequired], true);
    }
}
