<?php

namespace App\Domain\Supplier\Enums;

use App\Domain\Supplier\Models\SupplierOffer;

/**
 * How a Supplier came to hold an offer on a central Product.
 *
 * Both routes end in the same {@see SupplierOffer};
 * this only records which one it was.
 */
enum OfferOrigin: string
{
    /** The Supplier proposed it and authorised staff approved the listing. */
    case SupplierListing = 'supplier_listing';

    /** Authorised staff assigned an existing Product to the Supplier. */
    case AdminAssignment = 'admin_assignment';

    public function label(): string
    {
        return match ($this) {
            self::SupplierListing => 'Supplier listing',
            self::AdminAssignment => 'Assigned by Banij',
        };
    }
}
