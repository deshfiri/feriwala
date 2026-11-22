<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Order\Actions\AdvanceStoreOrder;
use App\Domain\Order\Actions\CancelStoreOrder;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Enums\StoreOrderAction;
use App\Domain\Order\Enums\StoreOrderStep;
use App\Domain\Order\Exceptions\StoreOrderRefused;
use App\Domain\Order\Models\Order;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * A storefront order's moves, platform-wide (D34). The order is listed on the one
 * Orders page and worked on its own order page, where its steps are shown; these
 * routes take the next step or cancel it.
 *
 * Reading is `order.view`; every move is `order.edit` and is a named action, not a
 * chosen status. The route can be called by hand, so the action refuses anything
 * but the next step on the server (and a Partner's session never reaches it).
 */
class StoreOrderController extends Controller
{
    public function __construct(
        protected AdvanceStoreOrder $advance,
        protected CancelStoreOrder $cancel,
    ) {}

    /**
     * Store orders are listed with every other order now (one Orders page, with
     * the source filter set to storefront orders). The old address still works:
     * it opens that list, carrying over a search and the storefront chosen.
     */
    public function index(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);

        Gate::forUser($actor)->authorize('viewAny', Order::class);

        return redirect()->route('admin.orders.index', array_filter([
            'source' => OrderSource::Website->value,
            'search' => $request->string('search')->toString() ?: null,
            'website' => $request->string('storefront')->toString() ?: null,
        ]));
    }

    /**
     * A storefront order is worked on its own order page, steps and all. The
     * old address still works: it opens that page.
     */
    public function show(Request $request, string $order): RedirectResponse
    {
        $actor = $this->actor($request);
        $record = $this->order($order);

        Gate::forUser($actor)->authorize('view', $record);

        return redirect()->route('admin.orders.show', $record->public_id);
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

    /**
     * Whether a storefront order may still be cancelled: not once it has left
     * the warehouse, and not from a state that already ends it.
     */
    public static function cancellable(Order $order): bool
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
