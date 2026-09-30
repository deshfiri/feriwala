<?php

namespace App\Domain\Supplier\Enums;

use App\Support\StateMachine\TransitionableState;
use App\Support\Status\HasTranslatedLabel;

/**
 * A Supplier listing lot's lifecycle (Supplier Bulk Product Listing batch).
 *
 * A lot's status is always derived by rolling up its child listings'
 * {@see ListingStatus} values -- see `SupplierProductListingLot::rollupStatus()`
 * -- the same way a listing's own status is already derived from its items'
 * {@see ListingItemStatus} values in `DecideSupplierListing`. Nothing
 * transitions a lot directly except that rollup and the two Supplier-driven
 * moves (`Draft`->`Submitted`, `Draft`->`Closed` for discarding an empty
 * draft).
 *
 * `Returned` here is the literal name this batch's spec asks for. One level
 * down, {@see ListingStatus::CorrectionRequired} means the identical thing
 * but keeps its existing name deliberately -- that enum already has ten-plus
 * production usages (tests, notifications, audit action strings, frontend
 * labels) for zero behavioural gain from a rename. This enum is brand new,
 * so there is no legacy to disturb by using the batch's own word directly.
 */
enum LotStatus: string implements TransitionableState
{
    use HasTranslatedLabel;

    case Draft = 'draft';
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case PartiallyApproved = 'partially_approved';
    case Approved = 'approved';
    case Returned = 'returned';
    case Rejected = 'rejected';
    case Closed = 'closed';

    /**
     * @return array<int, self>
     */
    public function transitionsTo(): array
    {
        return match ($this) {
            // Closed is reachable directly from Draft only to discard an
            // empty draft (ArchiveSupplierListingLotDraft) -- a draft with
            // any item in it is closed only by the rollup, never directly.
            self::Draft => [self::Submitted, self::Closed],
            self::Submitted => [self::UnderReview],

            self::UnderReview => [
                self::Approved, self::PartiallyApproved, self::Rejected, self::Returned,
            ],

            self::Returned => [self::Submitted],

            self::Approved, self::PartiallyApproved, self::Rejected => [self::Closed],

            self::Closed => [],
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Closed;
    }

    protected static function statusLabelGroup(): string
    {
        return 'supplier_listing_lot';
    }

    public function tone(): string
    {
        return match ($this) {
            self::Approved => 'success',
            self::PartiallyApproved => 'info',
            self::Rejected => 'danger',
            self::Returned => 'warning',
            self::UnderReview, self::Submitted => 'info',
            default => 'neutral',
        };
    }
}
