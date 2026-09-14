<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Policies\InventoryPolicy;
use App\Domain\Inventory\Queries\StockAvailability;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What every website is told about stock (§19.1, contract §5.2, P3-27).
 *
 * Every website sells from central stock, so there is one availability per SKU
 * and this screen shows it exactly as a website receives it — the same shape,
 * the same figures, from {@see StockAvailability}. Searching and the stock-state
 * filter run in the database.
 */
class AvailabilityController extends Controller
{
    public const PER_PAGE = 25;

    /** @var array<int, string> */
    public const STATES = ['in_stock', 'out_of_stock'];

    public function __construct(
        protected StockAvailability $availability,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);
        abort_unless(InventoryPolicy::canViewAny($actor), 403);

        $search = trim($request->string('search')->toString());
        $state = $request->string('state')->toString();

        $filters = [
            'search' => $search === '' ? null : mb_substr($search, 0, 100),
            'state' => in_array($state, self::STATES, true) ? $state : null,
        ];

        $term = $filters['search'];

        $page = Product::query()
            ->with('variants:id,product_id,sku')
            ->when($term !== null, fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->where('name', 'ilike', "%{$term}%")
                ->orWhere('sku', 'ilike', "%{$term}%")
                ->orWhereHas('variants', fn (Builder $variant) => $variant->where('sku', 'ilike', "%{$term}%"))))
            ->when($filters['state'] === 'in_stock', fn (Builder $query) => $query->whereExists($this->availableStock(...)))
            ->when($filters['state'] === 'out_of_stock', fn (Builder $query) => $query->whereNotExists($this->availableStock(...)))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        // Every unit on the page in one query, rather than two per product.
        $units = [];

        foreach ($page->getCollection() as $product) {
            if ($product->variants->isEmpty()) {
                $units[] = ['sku' => $product->sku, 'product_id' => $product->id, 'variant_id' => null];

                continue;
            }

            foreach ($product->variants as $variant) {
                /** @var ProductVariant $variant */
                $units[] = ['sku' => $variant->sku, 'product_id' => $product->id, 'variant_id' => $variant->id];
            }
        }

        $figures = $this->availability->forUnits($units);

        return Inertia::render('admin/inventory/availability', [
            'products' => $page->through(fn (Product $product) => [
                'id' => $product->public_id,
                'name' => $product->name,
                'sku' => $product->sku,
                'status_label' => $product->status->label(),
                'units' => array_values(array_map(
                    fn (string $sku) => $figures[$sku],
                    $product->variants->isEmpty() ? [$product->sku] : $product->variants->pluck('sku')->all(),
                )),
            ]),
            'filters' => $filters,
        ]);
    }

    /**
     * Available stock of the product held in an active warehouse.
     */
    protected function availableStock(QueryBuilder $query): void
    {
        $query->selectRaw('1')
            ->from('stock_items')
            ->join('warehouses', 'warehouses.id', '=', 'stock_items.warehouse_id')
            ->whereColumn('stock_items.product_id', 'products.id')
            ->where('warehouses.is_active', true)
            ->where('stock_items.available', '>', 0);
    }
}
