<?php

namespace App\Http\Controllers\Erp;

use App\Concerns\ResolvesBusinessAccount;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The central stock set aside for this business account, from its own side
 * (§19: user-allocated stock, P3-30).
 *
 * **Self-scoped by the membership, not by a parameter** (§31.3): the account is
 * the signed-in person's own, so there is no identifier in the URL to change,
 * and anything in the query string is ignored. Read-only — only Feriwala sets
 * stock aside or gives it back.
 *
 * Per SKU, summed across active warehouses, and **without warehouse identity**:
 * which shelf holds a partner's stock is Feriwala's business, as it is for every
 * storefront (contract §5.2). What is shown is what this account's orders can
 * draw on beyond shared stock.
 */
class AllocatedStockController extends Controller
{
    use ResolvesBusinessAccount;

    public function index(Request $request): Response
    {
        $account = $this->businessAccountFor($request);

        $rows = DB::table('stock_allocations')
            ->join('stock_items', 'stock_items.id', '=', 'stock_allocations.stock_item_id')
            ->join('warehouses', 'warehouses.id', '=', 'stock_items.warehouse_id')
            ->join('products', 'products.id', '=', 'stock_items.product_id')
            ->leftJoin('product_variants', 'product_variants.id', '=', 'stock_items.product_variant_id')
            ->where('stock_allocations.business_account_id', $account->id)
            ->where('stock_allocations.quantity', '>', 0)
            ->where('warehouses.is_active', true)
            ->groupBy('products.id', 'products.name', 'products.sku', 'product_variants.id', 'product_variants.sku')
            ->orderBy('products.name')
            ->orderByRaw('COALESCE(product_variants.sku, products.sku)')
            ->select([
                'products.name as product',
                DB::raw('COALESCE(product_variants.sku, products.sku) as sku'),
                DB::raw('SUM(stock_allocations.quantity) as quantity'),
                DB::raw('MAX(stock_allocations.updated_at) as updated_at'),
            ])
            ->get()
            ->map(fn (object $row) => [
                'sku' => (string) $row->sku,
                'product' => (string) $row->product,
                'quantity' => (int) $row->quantity,
                'updated_at' => CarbonImmutable::parse($row->updated_at)->toIso8601String(),
            ])
            ->all();

        return Inertia::render('allocated-stock/index', [
            'allocations' => $rows,
            'total' => array_sum(array_column($rows, 'quantity')),
            'can_trade' => $account->canTransact(),
        ]);
    }
}
