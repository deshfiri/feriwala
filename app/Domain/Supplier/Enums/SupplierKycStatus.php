<?php

namespace App\Domain\Supplier\Enums;

use App\Support\StateMachine\TransitionableState;
use App\Support\Status\HasTranslatedLabel;

/**
 * One round of Supplier KYC (D25, P13-7). Mirrors the shape of
 * `App\Domain\Kyc\Enums\KycStatus` on purpose — the same "draft, submit,
 * review, decide" shape the Client/Partner engine already established — while
 * remaining its own table and its own enum.
 */
enum SupplierKycStatus: string implements TransitionableState
{
    use HasTranslatedLabel;

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

    protected static function statusLabelGroup(): string
    {
        return 'supplier_kyc';
    }

    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::CorrectionRequired], true);
    }
}
