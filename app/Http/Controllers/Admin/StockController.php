<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Actions\SetLowStockThreshold;
use App\Domain\Inventory\Actions\TrackStock;
use App\Domain\Inventory\Enums\StockAdjustmentKind;
use App\Domain\Inventory\Exceptions\InventoryRefused;
use App\Domain\Inventory\Models\StockAllocation;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Policies\InventoryPolicy;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Central stock, per warehouse and SKU (§19).
 *
 * Filtering, searching and paging happen in the database (§39), and every filter
 * value is held to the ones it may take, so a query string cannot reach a query.
 * No price is ever part of these rows: stock is a count, and the screens that
 * count it have no business carrying what anything cost.
 */
class StockController extends Controller
{
    public const PER_PAGE = 25;

    /** @var array<int, string> */
    public const STATES = ['in_stock', 'low_stock', 'out_of_stock'];

    /** @var array<int, string> */
    public const SORTS = ['available', 'updated_at'];

    public function __construct(
        protected TrackStock $track,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);

        abort_unless(InventoryPolicy::canViewAny($actor), 403);

        $filters = $this->filters($request);

        return Inertia::render('admin/inventory/stock', [
            'items' => $this->query($filters)
                ->paginate(self::PER_PAGE)
                ->withQueryString()
                ->through(fn (StockItem $item) => $this->row($item)),
            'filters' => $filters,
            'warehouses' => Warehouse::query()
                ->inPriorityOrder()
                ->get()
                ->map(fn (Warehouse $warehouse) => [
                    'id' => $warehouse->public_id,
                    'code' => $warehouse->code,
                    'name' => $warehouse->name,
                    'is_active' => $warehouse->is_active,
                ])
                ->all(),

            // The SKU search behind "Hold a SKU", loaded only by partial reload.
            'units' => Inertia::optional(fn () => $this->unitMatches($request)),

            'can' => [
                'track' => InventoryPolicy::canEdit($actor),
            ],
        ]);
    }

    /**
     * One SKU in one warehouse: its figures, and every movement that made them
     * (P3-23).
     */
    public function show(Request $request, string $item): Response
    {
        $actor = $this->actor($request);

        abort_unless(InventoryPolicy::canViewAny($actor), 403);

        /** @var StockItem $record */
        $record = StockItem::query()
            ->with([
                'warehouse:id,public_id,code,name,is_active',
                'product:id,public_id,name,sku',
                'variant:id,public_id,product_id,sku',
            ])
            ->where('public_id', $item)
            ->firstOrFail();

        return Inertia::render('admin/inventory/stock-item', [
            'item' => $this->row($record),
            'movements' => StockMovement::query()
                ->with('actor:id,name')
                ->where('stock_item_id', $record->id)
                ->orderByDesc('id')
                ->paginate(self::PER_PAGE)
                ->withQueryString()
                ->through(fn (StockMovement $movement) => [
                    'id' => $movement->public_id,
                    'type' => $movement->type->value,
                    'type_label' => __('inventory.movement_types.'.$movement->type->value),
                    'from' => $movement->from_bucket?->value,
                    'to' => $movement->to_bucket?->value,
                    'quantity' => $movement->quantity,
                    'before' => $movement->before,
                    'after' => $movement->after,
                    'reason' => $movement->reason,
                    'actor' => $movement->actor?->name,
                    'occurred_at' => $movement->occurred_at->toIso8601String(),
                ]),

            // What the reserved bucket is holding, and for which orders (P3-25).
            // Active reservations first, then the most recent that ended.
            'reservations' => $record->reservations()
                ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
                ->orderByDesc('id')
                ->limit(50)
                ->get()
                ->map(fn (StockReservation $reservation) => [
                    'id' => $reservation->public_id,
                    'reference' => $reservation->reference,
                    'kind' => $reservation->kind->value,
                    'kind_label' => __('inventory.reservation_kinds.'.$reservation->kind->value),
                    'status' => $reservation->status->value,
                    'status_label' => __('inventory.reservation_statuses.'.$reservation->status->value),
                    'status_tone' => $reservation->status->tone(),
                    'quantity' => $reservation->quantity,
                    'expires_at' => $reservation->expires_at->toIso8601String(),
                    'ended_at' => ($reservation->committed_at ?? $reservation->released_at)?->toIso8601String(),
                    'release_reason' => $reservation->release_reason,
                ])
                ->all(),

            // What a person may do to this stock by hand, and exactly which
            // buckets each kind moves between (P3-24).
            'adjustment_kinds' => array_map(fn (StockAdjustmentKind $kind) => [
                'value' => $kind->value,
                'label' => __('inventory.adjustments.kinds.'.$kind->value),
                'from' => $kind->source()?->value,
                'to' => $kind->destination()?->value,
            ], StockAdjustmentKind::cases()),

            // Who this stock is set aside for (P3-30): accounts still holding
            // units first, then those emptied most recently.
            'allocations' => $record->allocations()
                ->with('account:id,public_id,name,status')
                ->orderByRaw('CASE WHEN quantity > 0 THEN 0 ELSE 1 END')
                ->orderByDesc('updated_at')
                ->limit(50)
                ->get()
                ->map(fn (StockAllocation $allocation) => StockAllocationController::row($allocation))
                ->all(),

            // The account search behind "Allocate to an account", loaded only by
            // partial reload, and only accounts that can trade.
            'accounts' => Inertia::optional(fn () => $this->accountMatches($request)),

            'can' => [
                'adjust' => InventoryPolicy::canEdit($actor),
                'allocate' => InventoryPolicy::canApprove($actor),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(InventoryPolicy::canEdit($actor), 403);

        $validated = $request->validate([
            'warehouse' => ['required', 'string', Rule::exists(Warehouse::class, 'public_id')],
            'product' => ['required', 'string', Rule::exists(Product::class, 'public_id')],
            'variant' => ['nullable', 'string', Rule::exists(ProductVariant::class, 'public_id')],
        ]);

        /** @var Warehouse $warehouse */
        $warehouse = Warehouse::query()->where('public_id', $validated['warehouse'])->firstOrFail();
        /** @var Product $product */
        $product = Product::query()->where('public_id', $validated['product'])->firstOrFail();
        $variant = filled($validated['variant'] ?? null)
            ? ProductVariant::query()->where('public_id', $validated['variant'])->first()
            : null;

        try {
            $item = $this->track->handle($actor, $warehouse, $product, $variant);
        } catch (InventoryRefused $refused) {
            throw ValidationException::withMessages([
                $refused->getMessage() === __('inventory.refused.warehouse_inactive') ? 'warehouse' : 'product' => $refused->getMessage(),
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('inventory.stock.tracked', [
            'sku' => $variant !== null ? $variant->sku : $product->sku,
            'warehouse' => $warehouse->name,
        ])]);

        return back();
    }

    /**
     * When this SKU in this warehouse counts as running low (P3-29).
     */
    public function threshold(Request $request, string $item, SetLowStockThreshold $thresholds): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(InventoryPolicy::canEdit($actor), 403);

        /** @var StockItem $record */
        $record = StockItem::query()->where('public_id', $item)->firstOrFail();

        $validated = $request->validate([
            'low_stock_threshold' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ]);

        $threshold = $validated['low_stock_threshold'] ?? null;

        $thresholds->handle($actor, $record, $threshold === null ? null : (int) $threshold);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('inventory.thresholds.saved')]);

        return back();
    }

    /**
     * @return array{search: string|null, warehouse: string|null, state: string|null, sort: string|null, direction: string}
     */
    protected function filters(Request $request): array
    {
        $search = trim($request->string('search')->toString());
        $warehouse = $request->string('warehouse')->toString();
        $state = $request->string('state')->toString();
        $sort = $request->string('sort')->toString();

        return [
            'search' => $search === '' ? null : mb_substr($search, 0, 100),
            'warehouse' => preg_match('/^[0-9A-Za-z]{26}$/', $warehouse) === 1 ? $warehouse : null,
            'state' => in_array($state, self::STATES, true) ? $state : null,
            'sort' => in_array($sort, self::SORTS, true) ? $sort : null,
            'direction' => $request->string('direction')->toString() === 'desc' ? 'desc' : 'asc',
        ];
    }

    /**
     * @param  array{search: string|null, warehouse: string|null, state: string|null, sort: string|null, direction: string}  $filters
     * @return EloquentBuilder<StockItem>
     */
    protected function query(array $filters): EloquentBuilder
    {
        $term = $filters['search'];

        return StockItem::query()
            ->with([
                'warehouse:id,public_id,code,name,is_active',
                'product:id,public_id,name,sku',
                'variant:id,public_id,product_id,sku',
            ])
            ->when($term !== null, fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->whereHas('product', fn (Builder $product) => $product
                    ->where('name', 'ilike', "%{$term}%")
                    ->orWhere('sku', 'ilike', "%{$term}%"))
                ->orWhereHas('variant', fn (Builder $variant) => $variant->where('sku', 'ilike', "%{$term}%"))))
            ->when($filters['warehouse'] !== null, fn (Builder $query) => $query
                ->whereHas('warehouse', fn (Builder $warehouse) => $warehouse->where('public_id', $filters['warehouse'])))
            ->when($filters['state'] === 'in_stock', fn (Builder $query) => $query->where('available', '>', 0))
            ->when($filters['state'] === 'out_of_stock', fn (Builder $query) => $query->where('available', 0))
            ->when($filters['state'] === 'low_stock', fn (Builder $query) => $query
                ->whereNotNull('low_stock_threshold')
                ->whereColumn('available', '<=', 'low_stock_threshold'))
            ->when(
                $filters['sort'] !== null,
                fn (Builder $query) => $query->orderBy((string) $filters['sort'], $filters['direction'] === 'desc' ? 'desc' : 'asc'),
            )
            ->orderBy('id');
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(StockItem $item): array
    {
        return [
            'id' => $item->public_id,
            'sku' => $item->sku(),
            'product' => [
                'id' => $item->product->public_id,
                'name' => $item->product->name,
            ],
            'variant_id' => $item->variant?->public_id,
            'warehouse' => [
                'id' => $item->warehouse->public_id,
                'code' => $item->warehouse->code,
                'name' => $item->warehouse->name,
                'is_active' => $item->warehouse->is_active,
            ],
            'buckets' => $item->buckets(),
            'low_stock_threshold' => $item->low_stock_threshold,
            'is_low' => $item->isLow(),
            'updated_at' => $item->updated_at->toIso8601String(),
        ];
    }

    /**
     * Stockable units matching a search: products without variations, and
     * variations — never a product that has variations, which is not stocked as
     * itself.
     *
     * @return array<int, array{product: string, variant: string|null, sku: string, name: string}>
     */
    protected function unitMatches(Request $request): array
    {
        $term = trim($request->string('unit_search')->toString());

        if (mb_strlen($term) < 2) {
            return [];
        }

        $products = Product::query()
            ->whereDoesntHave('variants')
            ->where(fn (Builder $query) => $query->where('name', 'ilike', "%{$term}%")->orWhere('sku', 'ilike', "%{$term}%"))
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'public_id', 'name', 'sku'])
            ->map(fn (Product $product) => [
                'product' => $product->public_id,
                'variant' => null,
                'sku' => $product->sku,
                'name' => $product->name,
            ]);

        $variants = ProductVariant::query()
            ->with('product:id,public_id,name')
            ->where(fn (Builder $query) => $query
                ->where('sku', 'ilike', "%{$term}%")
                ->orWhereHas('product', fn (Builder $product) => $product->where('name', 'ilike', "%{$term}%")))
            ->orderBy('sku')
            ->limit(10)
            ->get(['id', 'public_id', 'product_id', 'sku'])
            ->map(fn (ProductVariant $variant) => [
                'product' => $variant->product->public_id,
                'variant' => $variant->public_id,
                'sku' => $variant->sku,
                'name' => $variant->product->name,
            ]);

        return $products->concat($variants)->values()->all();
    }

    /**
     * Business accounts that can trade and match a search by name — the only
     * accounts stock can be set aside for (P3-30).
     *
     * @return array<int, array{id: string, name: string}>
     */
    protected function accountMatches(Request $request): array
    {
        $term = trim($request->string('account_search')->toString());

        if (mb_strlen($term) < 2) {
            return [];
        }

        $trading = array_values(array_map(
            fn (AccountStatus $status) => $status->value,
            array_filter(AccountStatus::cases(), fn (AccountStatus $status) => $status->canTransact()),
        ));

        return BusinessAccount::query()
            ->whereIn('status', $trading)
            ->where('name', 'ilike', '%'.addcslashes(mb_substr($term, 0, 100), '%_\\').'%')
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'public_id', 'name'])
            ->map(fn (BusinessAccount $account) => ['id' => $account->public_id, 'name' => $account->name])
            ->all();
    }

    protected function actor(Request $request): User
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);

        return $actor;
    }
}
