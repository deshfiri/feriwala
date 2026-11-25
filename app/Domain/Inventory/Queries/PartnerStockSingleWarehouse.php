<?php

namespace App\Domain\Inventory\Queries;

use App\Domain\Account\Models\BusinessAccount;
use Illuminate\Support\Facades\DB;

/**
 * The one Banij warehouse that can fill a whole Storefront order from a
 * Partner's own stock, under the single-parcel model: one shipment leaves from
 * one warehouse.
 *
 * Only lots whose warehouse is proven count. Of the warehouses whose lots alone
 * cover every line, the one holding the oldest stock is chosen (lowest id on a
 * tie), so the order still takes its units first in, first out within that
 * warehouse. Null when no
 * single warehouse covers the order: it is then reserved as before and held for
 * staff before any courier booking, never shipped as one parcel from two places.
 *
 * Read-only and unlocked: it only suggests. The reservation that follows takes
 * its units under the lots' own row locks, and fails like any other short
 * reservation if they went in the meantime.
 */
class PartnerStockSingleWarehouse
{
    /**
     * @param  list<array{product_id: int, product_variant_id: int|null, quantity: int}>  $lines  the order's Partner-stock lines
     */
    public function for(BusinessAccount $account, array $lines): ?int
    {
        if ($lines === []) {
            return null;
        }

        $needed = [];

        foreach ($lines as $line) {
            $key = $line['product_id'].':'.($line['product_variant_id'] ?? 0);
            $needed[$key] = ($needed[$key] ?? 0) + $line['quantity'];
        }

        // Proven units per warehouse and product variation, with each warehouse's oldest stock.
        $held = DB::table('partner_stock_cost_lots')
            ->where('business_account_id', $account->id)
            ->whereNotNull('warehouse_id')
            ->where('remaining_quantity', '>', 0)
            ->groupBy('warehouse_id', 'product_id', 'product_variant_id')
            ->get([
                'warehouse_id',
                DB::raw("product_id || ':' || coalesce(product_variant_id, 0) AS line_key"),
                DB::raw('SUM(remaining_quantity) AS units'),
                DB::raw('MIN(purchased_at) AS oldest'),
            ]);

        $candidates = [];

        foreach ($held->groupBy('warehouse_id') as $warehouseId => $rows) {
            $units = $rows->pluck('units', 'line_key');
            $covers = collect($needed)->every(fn (int $quantity, string $key) => (int) ($units[$key] ?? 0) >= $quantity);

            if ($covers) {
                $candidates[(int) $warehouseId] = (string) $rows->min('oldest');
            }
        }

        if ($candidates === []) {
            return null;
        }

        // Oldest stock first; the lowest warehouse id settles a tie, so the
        // same order always picks the same warehouse.
        uksort($candidates, fn (int $a, int $b) => [$candidates[$a], $a] <=> [$candidates[$b], $b]);

        return (int) array_key_first($candidates);
    }
}
