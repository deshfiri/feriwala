<?php

namespace App\Domain\Supplier\Data;

use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierOffer;
use App\Domain\Supplier\Models\SupplierOfferPriceChange;
use Carbon\CarbonImmutable;

/**
 * One order line's allocation to one Supplier offer, resolved on the server at
 * one moment (D25, P13-21).
 *
 * Holds the offer, and the exact price version that was in force — the two
 * figures an order line snapshots. Nothing here is ever built from anything a
 * browser or a Storefront sent.
 */
final class SupplierAllocation
{
    public function __construct(
        public readonly SupplierOffer $offer,
        public readonly SupplierOfferPriceChange $priceVersion,
        public readonly int $quantity,
        public readonly CarbonImmutable $allocatedAt,
    ) {}

    public function supplier(): Supplier
    {
        return $this->offer->supplier;
    }

    /**
     * The order-line columns that snapshot this allocation.
     *
     * @return array<string, mixed>
     */
    public function lineSnapshot(): array
    {
        $supplierRate = $this->priceVersion->supplier_rate;
        $platformRate = $this->priceVersion->platform_rate;

        return [
            'supplier_id' => $this->offer->supplier_id,
            'supplier_offer_id' => $this->offer->id,
            'supplier_offer_price_change_id' => $this->priceVersion->id,
            'supplier_rate' => $supplierRate,
            'platform_rate' => $platformRate,
            'platform_margin' => $platformRate->minus($supplierRate),
            'supplier_currency_code' => $supplierRate->currency->value,
            'supplier_allocated_quantity' => $this->quantity,
            'supplier_allocated_at' => $this->allocatedAt,
        ];
    }
}
