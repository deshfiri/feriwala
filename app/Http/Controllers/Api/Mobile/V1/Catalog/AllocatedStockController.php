<?php

namespace App\Http\Controllers\Api\Mobile\V1\Catalog;

use App\Concerns\ResolvesBusinessAccount;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Resources\Mobile\Catalog\AllocatedStockResource;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The central stock set aside for this business account (§19, P3-30).
 *
 * Self-scoped by the membership, not by a parameter (§31.3): there is no
 * identifier to change. Read-only — only Feriwala sets stock aside or gives it back.
 */
class AllocatedStockController extends MobileController
{
    use ResolvesBusinessAccount;

    /**
     * Allocated stock
     *
     * Stock set aside for this account, per SKU, summed across active warehouses
     * and without warehouse identity. `total` is the sum of every `quantity`;
     * `can_trade` says whether the account may currently transact.
     *
     * @response array{data: list<AllocatedStockResource>, total: int, can_trade: bool}
     */
    public function index(Request $request): JsonResponse
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

        return new JsonResponse([
            'data' => AllocatedStockResource::collection($rows),
            'total' => array_sum(array_column($rows, 'quantity')),
            'can_trade' => $account->canTransact(),
        ]);
    }
}
