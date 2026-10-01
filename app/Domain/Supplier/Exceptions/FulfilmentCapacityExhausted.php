<?php

namespace App\Domain\Supplier\Exceptions;

use App\Domain\Supplier\Models\SupplierOffer;
use RuntimeException;

/**
 * A non-ready-stock offer's declared `fulfilment_capacity` would be
 * exceeded by one more commitment (Supplier Bulk Product Listing batch,
 * correction 7).
 */
class FulfilmentCapacityExhausted extends RuntimeException
{
    public static function forOffer(SupplierOffer $offer, int $committed, int $requested): self
    {
        return new self(sprintf(
            'This offer can commit to %d units at once; %d are already committed and %d more were requested.',
            $offer->fulfilment_capacity,
            $committed,
            $requested,
        ));
    }
}
