<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Inventory\Actions\AllocateStock;
use App\Domain\Inventory\Actions\ReleaseAllocatedStock;
use App\Domain\Inventory\Exceptions\InventoryRefused;
use App\Domain\Inventory\Models\StockAllocation;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Policies\InventoryPolicy;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Central stock set aside for business accounts (§19: user-allocated stock, P3-30).
 *
 * Reading is `inventory.view`. Allocating and releasing are `inventory.approve`,
 * asked here before any rule runs and again in the actions: deciding that one
 * business gets stock ahead of everyone else is the same kind of decision as
 * overriding a reservation.
 */
class StockAllocationController extends Controller
{
    public const PER_PAGE = 25;

    public function __construct(
        protected AllocateStock $allocate,
        protected ReleaseAllocatedStock $release,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);

        abort_unless(InventoryPolicy::canViewAny($actor), 403);

        $search = trim($request->string('search')->toString());
        $term = $search === '' ? null : mb_substr($search, 0, 120);
        $held = $request->string('held')->toString() !== 'all';

        $allocations = StockAllocation::query()
            ->with([
                'account:id,public_id,name,status',
                'item:id,public_id,warehouse_id,product_id,product_variant_id',
                'item.warehouse:id,code,name,is_active',
                'item.product:id,name,sku',
                'item.variant:id,sku',
            ])
            ->when($held, fn (Builder $query) => $query->where('quantity', '>', 0))
            ->when($term !== null, fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->whereHas('account', fn (Builder $account) => $account->where('name', 'ilike', "%{$term}%"))
                ->orWhereHas('item.product', fn (Builder $product) => $product
                    ->where('name', 'ilike', "%{$term}%")
                    ->orWhere('sku', 'ilike', "%{$term}%"))
                ->orWhereHas('item.variant', fn (Builder $variant) => $variant->where('sku', 'ilike', "%{$term}%"))))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (StockAllocation $allocation) => [
                ...$this->row($allocation),
                'sku' => $allocation->item->sku(),
                'product' => $allocation->item->productName(),
                'item_id' => $allocation->item->public_id,
                'warehouse' => [
                    'code' => $allocation->item->warehouse->code,
                    'name' => $allocation->item->warehouse->name,
                    'is_active' => $allocation->item->warehouse->is_active,
                ],
            ]);

        return Inertia::render('admin/inventory/allocations', [
            'allocations' => $allocations,
            'filters' => ['search' => $term, 'held' => $held ? 'held' : 'all'],
            'can' => [
                'release' => InventoryPolicy::canApprove($actor),
            ],
        ]);
    }

    public function store(Request $request, string $item): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(InventoryPolicy::canApprove($actor), 403);

        /** @var StockItem $record */
        $record = StockItem::query()->with(['product', 'variant'])->where('public_id', $item)->firstOrFail();

        $validated = $request->validate([
            'account' => ['required', 'string', Rule::exists(BusinessAccount::class, 'public_id')->whereNull('deleted_at')],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'reason' => ['required', 'string', 'min:'.AllocateStock::MINIMUM_REASON, 'max:1000'],
        ]);

        /** @var BusinessAccount $account */
        $account = BusinessAccount::query()->where('public_id', $validated['account'])->firstOrFail();

        try {
            $this->allocate->handle($actor, $record, $account, (int) $validated['quantity'], $validated['reason']);
        } catch (InventoryRefused $refused) {
            throw ValidationException::withMessages([
                $refused->getMessage() === __('inventory.refused.account_cannot_hold_stock', ['account' => $account->name]) ? 'account' : 'quantity' => $refused->getMessage(),
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('inventory.allocations.allocated', [
            'quantity' => (int) $validated['quantity'],
            'sku' => $record->sku(),
            'account' => $account->name,
        ])]);

        return back();
    }

    public function release(Request $request, string $allocation): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(InventoryPolicy::canApprove($actor), 403);

        /** @var StockAllocation $record */
        $record = StockAllocation::query()->with(['account', 'item.product', 'item.variant'])->where('public_id', $allocation)->firstOrFail();

        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'reason' => ['required', 'string', 'min:'.AllocateStock::MINIMUM_REASON, 'max:1000'],
        ]);

        try {
            $this->release->handle($actor, $record, (int) $validated['quantity'], $validated['reason']);
        } catch (InventoryRefused $refused) {
            throw ValidationException::withMessages(['quantity' => $refused->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('inventory.allocations.released', [
            'quantity' => (int) $validated['quantity'],
            'sku' => $record->item->sku(),
            'account' => $record->account->name,
        ])]);

        return back();
    }

    /**
     * One allocation as the screens receive it. No price, no account detail beyond
     * its name and whether it can still trade.
     *
     * @return array<string, mixed>
     */
    public static function row(StockAllocation $allocation): array
    {
        return [
            'id' => $allocation->public_id,
            'account' => [
                'id' => $allocation->account->public_id,
                'name' => $allocation->account->name,
                'can_trade' => $allocation->account->canTransact(),
            ],
            'quantity' => $allocation->quantity,
            'updated_at' => $allocation->updated_at->toIso8601String(),
        ];
    }

    protected function actor(Request $request): User
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);

        return $actor;
    }
}
