<?php

namespace App\Domain\Inventory\Queries;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Catalog\Models\Product;
use App\Domain\Supplier\Queries\SupplierSourcedAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
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
 *
 * **User-allocated stock (P3-30)** has left the available bucket, so it is in no
 * website's shared figure. Asked on behalf of a business account, the answer adds
 * that account's own allocation — and only that account's: another account's
 * allocation is exactly the "other websites' allocation" the contract keeps out.
 */
class StockAvailability
{
    public function __construct(
        protected SupplierSourcedAvailability $supplierStock = new SupplierSourcedAvailability,
    ) {}

    /**
     * Availability for the given SKUs, keyed by SKU.
     *
     * A SKU the catalogue does not know is omitted; a stockable unit with no
     * stock held anywhere is reported as out of stock with a quantity of zero.
     * A product that has variations is not itself a stockable unit, so its own
     * SKU is omitted — its variations are reported instead.
     *
     * @param  array<array-key, mixed>  $skus  as a caller sent them; anything not a string is ignored
     * @param  BusinessAccount|null  $account  whose own allocated stock counts too (P3-30)
     * @return array<string, array{sku: string, in_stock: bool, quantity: int, updated_at: string|null}>
     */
    public function forSkus(array $skus, ?BusinessAccount $account = null): array
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
        ])->all(), $account);
    }

    /**
     * Availability for every stockable unit of one product, in variation order.
     *
     * @return array<int, array{sku: string, in_stock: bool, quantity: int, updated_at: string|null}>
     */
    public function forProduct(Product $product, ?BusinessAccount $account = null): array
    {
        $variants = $product->variants()->get(['id', 'product_id', 'sku']);

        $units = $variants->isEmpty()
            ? [['sku' => $product->sku, 'product_id' => $product->id, 'variant_id' => null]]
            : $variants->map(fn ($variant) => [
                'sku' => $variant->sku,
                'product_id' => $product->id,
                'variant_id' => $variant->id,
            ])->all();

        return array_values($this->forUnits($units, $account));
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
     * Whether any stockable unit of the product has central stock somebody can
     * order: available to anyone, or allocated to an account (P3-30) — stock set
     * aside for one partner is still stock that partner can sell, so the product
     * stays on sale while it lasts.
     */
    public function productHasStock(Product $product): bool
    {
        return DB::table('stock_items')
            ->join('warehouses', 'warehouses.id', '=', 'stock_items.warehouse_id')
            ->where('warehouses.is_active', true)
            ->where('stock_items.product_id', $product->id)
            ->where(fn ($query) => $query
                ->where('stock_items.available', '>', 0)
                ->orWhere('stock_items.allocated', '>', 0))
            ->exists();
    }

    /**
     * Narrow a product query to products with — or without — stock this account
     * can order (P4-1).
     *
     * The same figure {@see forUnits()} reports, stated as SQL so a catalogue
     * filter pages in the database: a stockable unit counts when its item sits in
     * an active warehouse, belongs to the product itself or to one of its active
     * variations, and holds available units or units allocated to this account.
     * Another account's allocation never counts.
     *
     * @param  EloquentBuilder<Product>  $products
     * @return EloquentBuilder<Product>
     */
    public function whereProductStock(EloquentBuilder $products, ?BusinessAccount $account, bool $inStock): EloquentBuilder
    {
        $exists = fn (QueryBuilder $query) => $query
            ->selectRaw('1')
            ->from('stock_items')
            ->join('warehouses', 'warehouses.id', '=', 'stock_items.warehouse_id')
            ->leftJoin('product_variants', 'product_variants.id', '=', 'stock_items.product_variant_id')
            ->leftJoin('stock_allocations', fn ($join) => $join
                ->on('stock_allocations.stock_item_id', '=', 'stock_items.id')
                ->where('stock_allocations.business_account_id', '=', $account->id ?? 0))
            ->whereColumn('stock_items.product_id', 'products.id')
            ->where('warehouses.is_active', true)
            ->where(fn (QueryBuilder $unit) => $unit
                ->whereNull('stock_items.product_variant_id')
                ->orWhere('product_variants.is_active', true))
            ->whereRaw('stock_items.available + COALESCE(stock_allocations.quantity, 0) > 0');

        return $inStock ? $products->whereExists($exists) : $products->whereNotExists($exists);
    }

    /**
     * Availability for stockable units already identified, in one query.
     *
     * @param  array<int, array{sku: string, product_id: int, variant_id: int|null}>  $units
     * @param  BusinessAccount|null  $account  whose own allocated stock counts too (P3-30)
     * @return array<string, array{sku: string, in_stock: bool, quantity: int, updated_at: string|null}>
     */
    public function forUnits(array $units, ?BusinessAccount $account = null): array
    {
        if ($units === []) {
            return [];
        }

        $productIds = array_values(array_unique(array_column($units, 'product_id')));

        // A unit whose stock comes from Suppliers is never central stock too
        // (D25): the moment any offer exists for it, its answer comes from
        // there instead, and central stock is not consulted for it at all.
        $supplierAnswers = $this->supplierStock->forUnits($units);

        $centralUnits = $supplierAnswers === []
            ? $units
            : array_values(array_filter(
                $units,
                fn (array $unit) => ! isset($supplierAnswers[$unit['product_id'].':'.($unit['variant_id'] ?? '')]),
            ));

        $totals = $centralUnits === [] ? collect() : DB::table('stock_items')
            ->join('warehouses', 'warehouses.id', '=', 'stock_items.warehouse_id')
            // Only the asking account's allocation joins; with no account, none does.
            ->leftJoin('stock_allocations', fn ($join) => $join
                ->on('stock_allocations.stock_item_id', '=', 'stock_items.id')
                ->where('stock_allocations.business_account_id', '=', $account->id ?? 0))
            ->where('warehouses.is_active', true)
            ->whereIn('stock_items.product_id', $productIds)
            ->groupBy('stock_items.product_id', 'stock_items.product_variant_id')
            ->select([
                'stock_items.product_id',
                'stock_items.product_variant_id',
                DB::raw('SUM(stock_items.available) + COALESCE(SUM(stock_allocations.quantity), 0) as quantity'),
                DB::raw('MAX(stock_items.updated_at) as updated_at'),
            ])
            ->get()
            ->keyBy(fn (object $row) => $row->product_id.':'.($row->product_variant_id ?? ''));

        $answers = [];

        foreach ($units as $unit) {
            $key = $unit['product_id'].':'.($unit['variant_id'] ?? '');
            $supplier = $supplierAnswers[$key] ?? null;

            if ($supplier !== null) {
                $answers[$unit['sku']] = [
                    'sku' => $unit['sku'],
                    'in_stock' => $supplier['quantity'] > 0,
                    'quantity' => $supplier['quantity'],
                    'updated_at' => $supplier['updated_at'],
                ];

                continue;
            }

            $row = $totals->get($key);
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
