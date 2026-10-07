<?php

namespace App\Http\Controllers\Api\Storefront\V1;

use App\Domain\Inventory\Queries\StockAvailability;
use App\Domain\Website\Api\StorefrontError;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteProduct;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A storefront reading stock for what it sells (contract §5.2).
 *
 * Availability only: never a warehouse, never a reservation, never another
 * website's allocation. And only for SKUs **this website has published** — a SKU
 * it does not sell is simply absent from a list and a `404` on its own, so the
 * endpoint cannot be used to probe what anybody else carries.
 *
 * The quantity is advisory. The binding check is the reservation made when an
 * order is submitted; a storefront treating this figure as a promise will
 * oversell, and the contract says so.
 */
class InventoryController extends StorefrontController
{
    public function __construct(
        protected StockAvailability $stock,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $website = $this->website($request);

        /** @var array<array-key, mixed> $asked */
        $asked = (array) $request->query('skus', []);

        $skus = $asked === []
            ? array_slice($this->publishedSkus($website), 0, $this->limit($request))
            : array_values(array_intersect(
                array_map(fn (mixed $sku) => is_string($sku) ? mb_strtoupper(trim($sku)) : '', $asked),
                $this->publishedSkus($website),
            ));

        return new JsonResponse([
            'data' => array_values($this->stock->forSkus($skus, $website->businessAccount)),
            'meta' => ['next_cursor' => null, 'has_more' => false],
        ]);
    }

    public function show(Request $request, string $sku): JsonResponse
    {
        $website = $this->website($request);
        $sku = mb_strtoupper(trim($sku));

        if (! in_array($sku, $this->publishedSkus($website), true)) {
            return StorefrontError::respond($request, 404, 'not_found', 'No such BPC on this website.');
        }

        $availability = $this->stock->forSkus([$sku], $website->businessAccount)[$sku] ?? null;

        if ($availability === null) {
            return StorefrontError::respond($request, 404, 'not_found', 'No such BPC on this website.');
        }

        return new JsonResponse($availability);
    }

    /**
     * Every sellable SKU of this website's published products.
     *
     * @return array<int, string>
     */
    protected function publishedSkus(Website $website): array
    {
        $productIds = WebsiteProduct::query()
            ->where('website_id', $website->id)
            ->published()
            ->pluck('product_id');

        if ($productIds->isEmpty()) {
            return [];
        }

        return DB::table('products')
            ->whereIn('products.id', $productIds)
            ->whereNull('products.deleted_at')
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('product_variants')
                ->whereColumn('product_variants.product_id', 'products.id'))
            ->pluck('sku')
            // A trashed product's variations are unstockable too (urgent
            // product-management fix) — raw SQL, so not scoped the way
            // `Product::query()` is by Eloquent's own soft-delete scope.
            ->merge(DB::table('product_variants')
                ->whereIn('product_id', $productIds)
                ->where('is_active', true)
                ->whereNotExists(fn ($query) => $query
                    ->selectRaw('1')
                    ->from('products')
                    ->whereColumn('products.id', 'product_variants.product_id')
                    ->whereNotNull('products.deleted_at'))
                ->pluck('sku'))
            ->map(fn (mixed $sku) => mb_strtoupper((string) $sku))
            ->unique()
            ->values()
            ->all();
    }
}
