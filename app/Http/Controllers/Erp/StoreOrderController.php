<?php

namespace App\Http\Controllers\Erp;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Cases\Queries\PartnerCasePayload;
use App\Domain\Order\Actions\CancelStoreOrder;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Enums\StoreOrderStep;
use App\Domain\Order\Exceptions\StoreOrderRefused;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Queries\ReturnEligibility;
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
 * A Partner's Store Orders: every order from their own storefronts, in one list.
 *
 * Read-only apart from cancelling an order that is still Pending. A Partner
 * never advances a status — confirming, packing and dispatching are the
 * platform's actions — and never reaches another Partner's order: every query
 * here starts from their own account.
 */
class StoreOrderController extends Controller
{
    use ResolvesBusinessAccount;

    public function __construct(
        protected StoreOrderIndex $index,
        protected StoreOrderTimeline $timeline,
        protected CancelStoreOrder $cancel,
        protected PartnerCasePayload $cases,
        protected ReturnEligibility $eligibility,
    ) {}

    public function index(Request $request): Response
    {
        $account = $this->businessAccountFor($request);

        $orders = $this->index->query($request, $account->id)
            ->paginate(StoreOrderIndex::PER_PAGE)
            ->withQueryString()
            ->through(fn (Order $order) => $this->index->row($order, forStaff: false));

        return Inertia::render('store-orders/index', [
            'orders' => $orders,
            'filters' => $request->only(['storefront', 'step', 'payment', 'from', 'to', 'search']),
            'steps' => array_map(fn (StoreOrderStep $step) => ['value' => $step->value, 'label' => $step->label()], StoreOrderStep::cases()),
            'storefronts' => Website::query()->where('business_account_id', $account->id)->orderBy('name')->get(['public_id', 'name'])
                ->map(fn (Website $website) => ['value' => $website->public_id, 'label' => $website->name])
                ->all(),
        ]);
    }

    public function show(Request $request, string $order): Response
    {
        $record = $this->own($request, $order);
        $record->load(['items', 'payment', 'website:id,public_id,name']);

        $timeline = $this->timeline->for($record);

        return Inertia::render('store-orders/show', [
            'order' => $this->index->row($record, forStaff: false) + [
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
            'urls' => [
                'back' => route('store-orders.index'),
                'cancel' => route('store-orders.cancel', $record->public_id),
                'advance' => null,
                'request_case' => route('cases.create', $record->public_id),
            ],
            // The Partner reads the whole timeline; they get no next-action button.
            'timeline' => ['next_action' => null] + $timeline,
            // Every return and exchange asked for on this order (D35).
            'cases' => $this->cases->forOrder($record),
            'can' => [
                'advance' => false,
                'cancel' => StoreOrderStep::for($record->status) === StoreOrderStep::Pending && ! $timeline['cancelled'],
                'request_case' => $this->mayRequestCase($request, $record),
            ],
        ]);
    }

    public function cancel(Request $request, string $order): RedirectResponse
    {
        $record = $this->own($request, $order);
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        try {
            $this->cancel->handle($record, $actor, $validated['reason'], asPartner: true);
        } catch (StoreOrderRefused $refused) {
            throw ValidationException::withMessages(['reason' => $refused->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('store_orders.cancelled')]);

        return back();
    }

    /**
     * Whether this person may ask for a return or an exchange on this order: it
     * can still come back, and they may manage the store it came through.
     */
    protected function mayRequestCase(Request $request, Order $order): bool
    {
        $user = $request->user();
        $website = $order->website_id === null ? null : Website::query()->find($order->website_id);

        return $user instanceof User
            && $website !== null
            && $this->eligibility->for($order)['eligible']
            && Gate::forUser($user)->allows('manage', $website);
    }

    /**
     * The order, if it is a Store Order of this Partner's account — a 404 otherwise.
     * A replacement order an exchange sent is one of them: it was sent from the same store.
     */
    protected function own(Request $request, string $publicId): Order
    {
        $account = $this->businessAccountFor($request);

        /** @var Order|null $order */
        $order = Order::query()
            ->where('public_id', $publicId)
            ->whereIn('source', [OrderSource::Website->value, OrderSource::Exchange->value])
            ->where('business_account_id', $account->id)
            ->first();

        abort_if($order === null, 404);

        return $order;
    }
}
