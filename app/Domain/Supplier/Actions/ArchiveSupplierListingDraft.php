<?php

namespace App\Domain\Supplier\Actions;

use App\Domain\Supplier\Enums\ListingStatus;
use App\Domain\Supplier\Enums\SupplierStatusChangeSource;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierProductListing;
use App\Support\StatusHistory\StatusChange;
use InvalidArgumentException;

/**
 * Withdraws a Supplier's own draft listing (D25, P13-9). Only ever a draft —
 * once submitted, a Supplier can no longer make it disappear from the queue
 * a reviewer may already be looking at; see {@see ListingStatus}.
 */
class ArchiveSupplierListingDraft
{
    public function handle(Supplier $supplier, SupplierProductListing $listing): SupplierProductListing
    {
        if ($listing->supplier_id !== $supplier->id) {
            throw new InvalidArgumentException('This listing does not belong to this Supplier.');
        }

        if ($listing->status !== ListingStatus::Draft) {
            throw new InvalidArgumentException('Only a draft listing can be archived.');
        }

        $listing->transitionWithHistory(
            ListingStatus::Archived,
            new StatusChange(reason: 'Draft withdrawn by the Supplier.'),
            ['source' => SupplierStatusChangeSource::Supplier],
        );

        return $listing->refresh();
    }
}
