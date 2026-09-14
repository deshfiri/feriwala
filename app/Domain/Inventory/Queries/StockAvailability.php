<?php

namespace App\Domain\Inventory\Queries;

use App\Domain\Catalog\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What any website is told about a SKU's stock (§19, §19.1, contract §5.2).
 *
 * §19.1: when the same product is published on several websites, **all of them
 * use central stock**. So there is one answer per SKU, and every website gets the
 * same one — this class is it, and nothing else computes availability.
 *
 * The answer is the contract's shape and nothing more: the SKU, whether it is in
 * stock, how many are available, and when that last changed. `quantity` is the
 * available bucket summed across **active** warehouses only — a switched-off
 * warehouse's shelf is not somewhere an order can be filled from — and never
 * includes stock already reserved, processing, sold, returned or damaged.
 * Warehouse identity and reservation detail never leave this class (contract
 * §5.2: "never warehouse identity, never reservation detail, never other
 * websites' allocation").
 *
 * The figure is advisory at browse time; the binding check is the reservation
 * at order submission (P3-25), which is transactional.
 */
class StockAvailability
{
    /**
     * Availability for the given SKUs, keyed by SKU.
     *
     * A SKU the catalogue does not know is omitted; a stockable unit with no
     * stock held anywhere is reported as out of stock with a quantity of zero.
     * A product that has variations is not itself a stockable unit, so its own
     * SKU is omitted — its variations are reported instead.
     *
     * @param  array<array-key, mixed>  $skus  as a caller sent them; anything not a string is ignored
     * @return array<string, array{sku: string, in_stock: bool, quantity: int, updated_at: string|null}>
     */
    public function forSkus(array $skus): array
    {
        $skus = array_values(array_unique(array_filter(array_map(
            fn (mixed $sku) => is_string($sku) ? mb_strtoupper(trim($sku)) : null,
            $skus,
        ))));

        if ($skus === []) {
            return [];
        }

        $units = DB::table('products')
            ->whereIn('products.sku', $skus)
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('product_variants')
                ->whereColumn('product_variants.product_id', 'products.id'))
            ->select(['products.sku', 'products.id as product_id', DB::raw('NULL::bigint as variant_id')])
            ->unionAll(DB::table('product_variants')
                ->whereIn('product_variants.sku', $skus)
                ->select(['product_variants.sku', 'product_variants.product_id', 'product_variants.id as variant_id']))
            ->get();

        return $this->forUnits($units->map(fn (object $unit) => [
            'sku' => (string) $unit->sku,
            'product_id' => (int) $unit->product_id,
            'variant_id' => $unit->variant_id === null ? null : (int) $unit->variant_id,
        ])->all());
    }

    /**
     * Availability for every stockable unit of one product, in variation order.
     *
     * @return array<int, array{sku: string, in_stock: bool, quantity: int, updated_at: string|null}>
     */
    public function forProduct(Product $product): array
    {
        $variants = $product->variants()->get(['id', 'product_id', 'sku']);

        $units = $variants->isEmpty()
            ? [['sku' => $product->sku, 'product_id' => $product->id, 'variant_id' => null]]
            : $variants->map(fn ($variant) => [
                'sku' => $variant->sku,
                'product_id' => $product->id,
                'variant_id' => $variant->id,
            ])->all();

        return array_values($this->forUnits($units));
    }

    /**
     * Whether any of the product's stock is held anywhere — whether it has been
     * brought into inventory at all.
     */
    public function productIsTracked(Product $product): bool
    {
        return DB::table('stock_items')->where('product_id', $product->id)->exists();
    }

    /**
     * Whether any stockable unit of the product has central stock available.
     */
    public function productHasStock(Product $product): bool
    {
        return DB::table('stock_items')
            ->join('warehouses', 'warehouses.id', '=', 'stock_items.warehouse_id')
            ->where('warehouses.is_active', true)
            ->where('stock_items.product_id', $product->id)
            ->where('stock_items.available', '>', 0)
            ->exists();
    }

    /**
     * Availability for stockable units already identified, in one query.
     *
     * @param  array<int, array{sku: string, product_id: int, variant_id: int|null}>  $units
     * @return array<string, array{sku: string, in_stock: bool, quantity: int, updated_at: string|null}>
     */
    public function forUnits(array $units): array
    {
        if ($units === []) {
            return [];
        }

        $productIds = array_values(array_unique(array_column($units, 'product_id')));

        $totals = DB::table('stock_items')
            ->join('warehouses', 'warehouses.id', '=', 'stock_items.warehouse_id')
            ->where('warehouses.is_active', true)
            ->whereIn('stock_items.product_id', $productIds)
            ->groupBy('stock_items.product_id', 'stock_items.product_variant_id')
            ->select([
                'stock_items.product_id',
                'stock_items.product_variant_id',
                DB::raw('SUM(stock_items.available) as quantity'),
                DB::raw('MAX(stock_items.updated_at) as updated_at'),
            ])
            ->get()
            ->keyBy(fn (object $row) => $row->product_id.':'.($row->product_variant_id ?? ''));

        $answers = [];

        foreach ($units as $unit) {
            $row = $totals->get($unit['product_id'].':'.($unit['variant_id'] ?? ''));
            $quantity = $row === null ? 0 : (int) $row->quantity;

            $answers[$unit['sku']] = [
                'sku' => $unit['sku'],
                'in_stock' => $quantity > 0,
                'quantity' => $quantity,
                'updated_at' => $row?->updated_at === null ? null : CarbonImmutable::parse($row->updated_at)->toIso8601String(),
            ];
        }

        return $answers;
    }
}
