<?php

namespace App\Domain\Supplier\Enums;

use App\Support\StateMachine\TransitionableState;
use App\Support\Status\HasTranslatedLabel;

/**
 * A Product Listing Request's lifecycle (D25, contract-equivalent §11/§12,
 * P13-11).
 *
 * A listing is a proposal, never a publication: nothing here writes to
 * `products`, and no status in this map means "live on the catalogue" —
 * `Approved`/`PartiallyApproved` means Admin has connected or created the
 * central product and priced it, which is a separate, explicit step recorded
 * on the listing (`connected_product_id`) and on each item
 * (`connected_product_variant_id`, `supplier_offer_id`).
 */
enum ListingStatus: string implements TransitionableState
{
    use HasTranslatedLabel;

    case Draft = 'draft';
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case CorrectionRequired = 'correction_required';
    case Approved = 'approved';
    case PartiallyApproved = 'partially_approved';
    case Rejected = 'rejected';
    case Suspended = 'suspended';
    case Archived = 'archived';

    /**
     * @return array<int, self>
     */
    public function transitionsTo(): array
    {
        return match ($this) {
            self::Draft => [self::Submitted, self::Archived],
            self::Submitted => [self::UnderReview],

            self::UnderReview => [
                self::Approved, self::PartiallyApproved, self::Rejected,
                self::CorrectionRequired, self::Archived,
            ],

            self::CorrectionRequired => [self::Submitted, self::Archived],

            self::Approved, self::PartiallyApproved => [self::Suspended, self::Archived],
            self::Suspended => [self::Approved, self::PartiallyApproved, self::Archived],

            self::Rejected => [self::Archived],
            self::Archived => [],
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Archived;
    }

    protected static function statusLabelGroup(): string
    {
        return 'listing';
    }

    public function tone(): string
    {
        return match ($this) {
            self::Approved => 'success',
            self::PartiallyApproved => 'info',
            self::Rejected, self::Suspended => 'danger',
            self::CorrectionRequired => 'warning',
            self::UnderReview, self::Submitted => 'info',
            default => 'neutral',
        };
    }
}
