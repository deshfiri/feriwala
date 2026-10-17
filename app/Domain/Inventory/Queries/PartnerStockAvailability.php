<?php

namespace App\Domain\Inventory\Queries;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\PartnerStockReservations;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What a storefront is told about a SKU's stock: what its **Partner owns** (D34).
 *
 * The answer has the contract's shape and nothing more — the SKU, whether it is
 * in stock, how many, and when that last changed — but its source is the
 * Partner's own stock, never the central warehouse or a Supplier. `quantity` is
 * the `available` bucket of the Partner's row for that exact Variation: units
 * already reserved, committed to an order, returned or damaged are not in it, and
 * another Partner's units, another Variation's and central stock never are.
 *
 * Advisory at browse time; the binding check is the reservation at order
 * placement, which is transactional ({@see PartnerStockReservations}).
 * Warehouse identity, reservation detail and every other Partner's stock stay in
 * the database (contract §5.2).
 */
class PartnerStockAvailability
{
    public function __construct(
        protected StockAvailability $units,
    ) {}

    /**
     * Availability for the given SKUs, keyed by SKU.
     *
     * A SKU the catalogue does not know is omitted; a unit the Partner owns none
     * of is reported out of stock with a quantity of zero.
     *
     * @param  array<array-key, mixed>  $skus  as a caller sent them; anything not a string is ignored
     * @return array<string, array{sku: string, in_stock: bool, quantity: int, updated_at: string|null}>
     */
    public function forSkus(array $skus, ?BusinessAccount $account = null): array
    {
        return $this->forUnits($this->units->unitsForSkus($skus), $account);
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
     * Availability for units already identified, in one query.
     *
     * @param  array<int, array{sku: string, product_id: int, variant_id: int|null}>  $units
     * @return array<string, array{sku: string, in_stock: bool, quantity: int, updated_at: string|null}>
     */
    public function forUnits(array $units, ?BusinessAccount $account = null): array
    {
        if ($units === []) {
            return [];
        }

        $rows = $account === null ? collect() : DB::table('partner_stock_items')
            ->where('business_account_id', $account->id)
            ->whereIn('product_id', array_values(array_unique(array_column($units, 'product_id'))))
            ->get(['product_id', 'product_variant_id', 'available', 'updated_at'])
            ->keyBy(fn (object $row) => $row->product_id.':'.($row->product_variant_id ?? ''));

        $answers = [];

        foreach ($units as $unit) {
            $row = $rows->get($unit['product_id'].':'.($unit['variant_id'] ?? ''));
            $quantity = $row === null ? 0 : (int) $row->available;

            $answers[$unit['sku']] = [
                'sku' => $unit['sku'],
                'in_stock' => $quantity > 0,
                'quantity' => $quantity,
                'updated_at' => $row?->updated_at === null ? null : CarbonImmutable::parse($row->updated_at)->toIso8601String(),
            ];
        }

        return $answers;
    }

    /**
     * Whether the product in `$productColumn` has a unit in stock for this
     * Partner, as a SQL condition — the answer {@see forUnits()} gives, stated
     * for the database so a storefront's `in_stock` filter pages there.
     *
     * `$productColumn` is a column reference written in the caller's code, never
     * input — which `literal-string` makes PHPStan hold it to.
     *
     * @param  literal-string  $productColumn
     * @return array{0: literal-string, 1: list<int|string>} the condition and its bindings
     */
    public function inStockSql(string $productColumn, ?BusinessAccount $account): array
    {
        $sql = <<<SQL
            EXISTS (
                SELECT 1 FROM partner_stock_items psi
                LEFT JOIN product_variants pv ON pv.id = psi.product_variant_id
                WHERE psi.business_account_id = ?
                    AND psi.product_id = {$productColumn}
                    AND psi.available > 0
                    AND (psi.product_variant_id IS NULL OR pv.is_active)
            )
            SQL;

        return [$sql, [$account->id ?? 0]];
    }
}
