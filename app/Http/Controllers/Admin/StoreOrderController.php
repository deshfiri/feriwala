<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Order\Actions\AdvanceStoreOrder;
use App\Domain\Order\Actions\CancelStoreOrder;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Enums\StoreOrderAction;
use App\Domain\Order\Enums\StoreOrderStep;
use App\Domain\Order\Exceptions\StoreOrderRefused;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Queries\StoreOrderIndex;
use App\Domain\Order\Queries\StoreOrderTimeline;
use App\Domain\Website\Models\Website;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Store Orders, platform-wide (D34): every Partner's storefront orders, with who
 * and which shop, and the one next action each can take.
 *
 * Reading is `order.view`; every move is `order.edit` and is a named action, not a
 * chosen status. The route can be called by hand, so the action refuses anything
 * but the next step on the server (and a Partner's session never reaches it).
 */
class StoreOrderController extends Controller
{
    public function __construct(
        protected StoreOrderIndex $index,
        protected StoreOrderTimeline $timeline,
        protected AdvanceStoreOrder $advance,
        protected CancelStoreOrder $cancel,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);

        Gate::forUser($actor)->authorize('viewAny', Order::class);

        $orders = $this->index->query($request)
            ->paginate(StoreOrderIndex::PER_PAGE)
            ->withQueryString()
            ->through(fn (Order $order) => $this->index->row($order, forStaff: true));

        return Inertia::render('admin/store-orders/index', [
            'orders' => $orders,
            'filters' => $request->only(['partner', 'storefront', 'step', 'payment', 'from', 'to', 'search']),
            'steps' => array_map(fn (StoreOrderStep $step) => ['value' => $step->value, 'label' => $step->label()], StoreOrderStep::cases()),
            'payments' => array_map(fn (PaymentStatus $status) => $status->value, PaymentStatus::cases()),
            'partners' => BusinessAccount::query()
                ->whereIn('id', Website::query()->select('business_account_id'))
                ->orderBy('name')
                ->get(['public_id', 'name'])
                ->map(fn (BusinessAccount $account) => ['value' => $account->public_id, 'label' => $account->name])
                ->all(),
            'storefronts' => Website::query()->orderBy('name')->get(['public_id', 'name'])
                ->map(fn (Website $website) => ['value' => $website->public_id, 'label' => $website->name])
                ->all(),
        ]);
    }

    public function show(Request $request, string $order): Response
    {
        $actor = $this->actor($request);
        $record = $this->order($order);

        Gate::forUser($actor)->authorize('view', $record);

        $record->load(['businessAccount:id,public_id,name', 'website:id,public_id,name', 'items', 'payment']);

        $timeline = $this->timeline->for($record);
        $mayMove = Gate::forUser($actor)->allows('transition', $record);

        return Inertia::render('admin/store-orders/show', [
            'order' => $this->index->row($record, forStaff: true) + [
                'customer_phone' => $record->customer['mobile'] ?? null,
                'shipping_address' => $record->shipping_address,
                'cancellation_reason' => $record->cancellation_reason,
                'items' => $record->items->map(fn ($item) => [
                    'sku' => $item->sku,
                    'name' => $item->product_name,
                    'variant' => $item->variant_label,
                    'quantity' => $item->quantity,
                    'line_total' => $item->line_total->jsonSerialize(),
                ])->all(),
            ],
            'timeline' => $timeline,
            'urls' => [
                'back' => route('admin.store-orders.index'),
                'cancel' => route('admin.store-orders.cancel', $record->public_id),
                'advance' => route('admin.store-orders.advance', [$record->public_id, '__action__']),
            ],
            'can' => [
                'advance' => $mayMove && $timeline['next_action'] !== null,
                'cancel' => $mayMove && ! $timeline['cancelled'] && $this->cancellable($record),
            ],
        ]);
    }

    public function advance(Request $request, string $order, string $action): RedirectResponse
    {
        $actor = $this->actor($request);
        $record = $this->order($order);

        Gate::forUser($actor)->authorize('transition', $record);

        $named = StoreOrderAction::tryFrom($action);

        abort_if($named === null, 404);

        $validated = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        try {
            $this->advance->handle($record, $named, $actor, $validated['note'] ?? null);
        } catch (StoreOrderRefused $refused) {
            throw ValidationException::withMessages(['action' => $refused->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $named->label()]);

        return back();
    }

    public function cancel(Request $request, string $order): RedirectResponse
    {
        $actor = $this->actor($request);
        $record = $this->order($order);

        Gate::forUser($actor)->authorize('transition', $record);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        try {
            $this->cancel->handle($record, $actor, $validated['reason']);
        } catch (StoreOrderRefused $refused) {
            throw ValidationException::withMessages(['reason' => $refused->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('store_orders.cancelled')]);

        return back();
    }

    protected function cancellable(Order $order): bool
    {
        return ! StoreOrderStep::hasDispatched($order->status)
            && ! in_array($order->status->value, ['cancelled', 'refund_pending', 'refunded', 'on_hold', 'courier_assigned'], true);
    }

    protected function order(string $publicId): Order
    {
        /** @var Order $order */
        $order = Order::query()
            ->where('public_id', $publicId)
            ->where('source', OrderSource::Website->value)
            ->firstOrFail();

        return $order;
    }

    protected function actor(Request $request): User
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);

        return $actor;
    }
}
