<?php

namespace App\Domain\Supplier\Queries;

use App\Domain\Inventory\Queries\StockAvailability;
use App\Domain\Supplier\Enums\OfferStatus;
use App\Domain\Supplier\Enums\SupplierStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What the shared availability answer says about Supplier-sourced units
 * (D25, P13-28).
 *
 * {@see StockAvailability} asks here for the
 * units it was given and lets the answer replace the central one for any that
 * are Supplier-sourced. A unit's figure is what its **preferred** offer can
 * serve — zero when that offer is suspended, its Supplier is not operational,
 * or none is preferred — because that is the one offer an order would be
 * allocated to. Only the quantity leaves this class: never the Supplier, the
 * offer, or a rate.
 */
class SupplierSourcedAvailability
{
    /**
     * @param  array<int, array{product_id: int, variant_id: int|null}>  $units
     * @return array<string, array{quantity: int, updated_at: string|null}> keyed `productId:variantId`, Supplier-sourced units only
     */
    public function forUnits(array $units): array
    {
        if ($units === []) {
            return [];
        }

        $productIds = array_values(array_unique(array_column($units, 'product_id')));

        $rows = DB::table('supplier_offers as offers')
            ->join('suppliers', 'suppliers.id', '=', 'offers.supplier_id')
            ->leftJoin('supplier_offer_stock as stock', 'stock.supplier_offer_id', '=', 'offers.id')
            ->whereIn('offers.product_id', $productIds)
            ->groupBy('offers.product_id', 'offers.product_variant_id')
            ->select(['offers.product_id', 'offers.product_variant_id'])
            ->selectRaw(
                'COALESCE(MAX(CASE WHEN offers.is_preferred AND offers.status = ? AND suppliers.status = ? THEN stock.quantity END), 0) as quantity',
                [OfferStatus::Active->value, SupplierStatus::Approved->value],
            )
            ->selectRaw('MAX(stock.updated_at) as updated_at')
            ->get();

        $answers = [];

        foreach ($rows as $row) {
            $answers[$row->product_id.':'.($row->product_variant_id ?? '')] = [
                'quantity' => (int) $row->quantity,
                'updated_at' => $row->updated_at === null ? null : CarbonImmutable::parse($row->updated_at)->toIso8601String(),
            ];
        }

        return $answers;
    }
}
