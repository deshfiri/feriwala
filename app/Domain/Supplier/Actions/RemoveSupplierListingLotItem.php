<?php

namespace App\Domain\Supplier\Actions;

use App\Domain\Supplier\Enums\ListingStatus;
use App\Domain\Supplier\Enums\SupplierStatusChangeSource;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierProductListing;
use App\Support\StatusHistory\StatusChange;
use InvalidArgumentException;

/**
 * Removes one product entry from a Supplier's lot (Supplier Bulk Product
 * Listing batch) -- while it is still `Draft` or `CorrectionRequired`, the
 * pair the batch's own spec names ("Edit/remove entries while Draft or
 * Returned").
 *
 * A listing row is never hard-deleted (`supplier_product_listings_no_delete`
 * guards that unconditionally, and always has — a listing sent back for
 * correction already carries real status history and decision notes that
 * must survive). "Removing" it is the same {@see ListingStatus::Archived}
 * transition {@see ArchiveSupplierListingDraft} already uses for a plain
 * draft, generalised to the wider pair this action covers.
 */
class RemoveSupplierListingLotItem
{
    public function handle(Supplier $supplier, SupplierProductListing $listing): SupplierProductListing
    {
        if ($listing->supplier_id !== $supplier->id) {
            throw new InvalidArgumentException('This product entry does not belong to this Supplier.');
        }

        if (! in_array($listing->status, [ListingStatus::Draft, ListingStatus::CorrectionRequired], true)) {
            throw new InvalidArgumentException('This product entry can no longer be removed.');
        }

        $listing->transitionWithHistory(
            ListingStatus::Archived,
            new StatusChange(reason: 'Removed from the lot by the Supplier.'),
            ['source' => SupplierStatusChangeSource::Supplier],
        );

        return $listing->refresh();
    }
}
