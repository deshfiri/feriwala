<?php

namespace App\Domain\Supplier\Actions;

use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierProductListingLot;

/**
 * Starts a Supplier's draft listing lot (Supplier Bulk Product Listing
 * batch) -- an empty container a Supplier then adds product entries to
 * through the lot workspace, one submission for all of them.
 */
class CreateSupplierListingLot
{
    public function handle(Supplier $supplier, ?string $title = null): SupplierProductListingLot
    {
        return $supplier->lots()->create([
            'title' => $title,
        ]);
    }
}
