<?php

namespace App\Domain\Supplier\Actions;

use App\Domain\Supplier\Enums\LotStatus;
use App\Domain\Supplier\Enums\SupplierStatusChangeSource;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierProductListingLot;
use App\Support\StatusHistory\StatusChange;
use InvalidArgumentException;

/**
 * Discards a Supplier's own empty draft lot (Supplier Bulk Product Listing
 * batch), mirroring {@see ArchiveSupplierListingDraft} one level up.
 *
 * Refused while the lot holds any item: a lot with product entries in it is
 * closed by {@see SupplierProductListingLot::rollupStatus()} once every item
 * has run its own course, never discarded out from under items a reviewer
 * may already be looking at -- the same reasoning that keeps a submitted
 * single listing from being archived.
 */
class ArchiveSupplierListingLotDraft
{
    public function handle(Supplier $supplier, SupplierProductListingLot $lot): SupplierProductListingLot
    {
        if ($lot->supplier_id !== $supplier->id) {
            throw new InvalidArgumentException('This lot does not belong to this Supplier.');
        }

        if ($lot->status !== LotStatus::Draft) {
            throw new InvalidArgumentException('Only a draft lot can be discarded.');
        }

        if ($lot->items()->exists()) {
            throw new InvalidArgumentException('Remove every product entry before discarding this lot.');
        }

        $lot->transitionWithHistory(
            LotStatus::Closed,
            new StatusChange(reason: 'Empty draft lot discarded by the Supplier.'),
            ['source' => SupplierStatusChangeSource::Supplier],
        );

        return $lot->refresh();
    }
}
