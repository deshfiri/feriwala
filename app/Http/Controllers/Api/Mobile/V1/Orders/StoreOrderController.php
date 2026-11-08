<?php

namespace App\Http\Controllers\Api\Mobile\V1\Orders;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Cases\Queries\CaseLinks;
use App\Domain\Cases\Queries\PartnerCasePayload;
use App\Domain\Courier\Queries\StoreOrderCourierPanel;
use App\Domain\Damage\Queries\DamageReportEligibility;
use App\Domain\Order\Actions\CancelStoreOrder;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Enums\StoreOrderStep;
use App\Domain\Order\Exceptions\StoreOrderRefused;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Queries\ReturnEligibility;
use App\Domain\Order\Queries\StoreOrderIndex;
use App\Domain\Order\Queries\StoreOrderTimeline;
use App\Domain\Website\Models\Website;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Requests\Mobile\Orders\CancelStoreOrderRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * A Partner's Store Orders: every order from their own storefronts, in one list.
 *
 * Read-only apart from cancelling an order that is still Pending. A Partner
 * never advances a status — confirming, packing and dispatching are the
 * platform's actions — and never reaches another Partner's order: every query
 * here starts from their own account.
 */
class StoreOrderController extends MobileController
{
    use ResolvesBusinessAccount;

    public function __construct(
        protected StoreOrderIndex $index,
        protected StoreOrderTimeline $timeline,
        protected CancelStoreOrder $cancellations,
        protected PartnerCasePayload $cases,
        protected ReturnEligibility $eligibility,
        protected CaseLinks $links,
        protected DamageReportEligibility $damageEligibility,
        protected StoreOrderCourierPanel $courier,
    ) {}

    /**
     * List store orders
     *
     * Every order this account's own storefronts took, newest first, 25 to a page. Filter with
     * `storefront` (a storefront `id`), `step`, `payment`, `from`, `to` and `search`. `filters`
     * echoes what was applied; `steps` and `storefronts` list the choices.
     *
     * @response array{data: list<array<string, mixed>>, links: array<string, string|null>, meta: array<string, mixed>, filters: array<string, mixed>, steps: list<array{value: string, label: string}>, storefronts: list<array{value: string, label: string}>}
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $account = $this->businessAccountFor($request);

        $orders = $this->index->query($request, $account->id)
            ->paginate(StoreOrderIndex::PER_PAGE)
            ->withQueryString()
            ->through(fn (Order $order) => $this->index->row($order, forStaff: false));

        return JsonResource::collection($orders)->additional([
            'filters' => $request->only(['storefront', 'step', 'payment', 'from', 'to', 'search']),
            'steps' => array_map(fn (StoreOrderStep $step) => ['value' => $step->value, 'label' => $step->label()], StoreOrderStep::cases()),
            'storefronts' => Website::query()->where('business_account_id', $account->id)->orderBy('name')->get(['public_id', 'name'])
                ->map(fn (Website $website) => ['value' => $website->public_id, 'label' => $website->name])
                ->all(),
        ]);
    }

    /**
     * Store order detail
     *
     * One order in full: customer, address, lines, the platform's timeline, the courier (as the
     * customer sees it), every return and exchange asked for, and what this person may do
     * (`can`). The partner reads the whole timeline but gets no next-action. `404` for another
     * account's order.
     *
     * @response array{data: array<string, mixed>, timeline: array<string, mixed>, courier: mixed, cases: mixed, exchange_origin: mixed, can: array{advance: bool, cancel: bool, request_case: bool, report_damage: bool}}
     */
    public function show(Request $request, string $order): JsonResponse
    {
        return $this->detail($request, $this->own($request, $order));
    }

    /**
     * Cancel a store order
     *
     * Cancels an order that is still Pending, with a `reason`, and returns the order as `show`
     * does. `404` for another account's order; `422` when the order can no longer be cancelled.
     *
     * @response array{data: array<string, mixed>, timeline: array<string, mixed>, courier: mixed, cases: mixed, exchange_origin: mixed, can: array{advance: bool, cancel: bool, request_case: bool, report_damage: bool}}
     */
    public function cancel(CancelStoreOrderRequest $request, string $order): JsonResponse
    {
        $record = $this->own($request, $order);
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);

        try {
            $this->cancellations->handle($record, $actor, $request->validated('reason'), asPartner: true);
        } catch (StoreOrderRefused $refused) {
            throw ValidationException::withMessages(['reason' => $refused->getMessage()]);
        }

        return $this->detail($request, $record->refresh());
    }

    protected function detail(Request $request, Order $order): JsonResponse
    {
        $order->load(['items', 'payment', 'website:id,public_id,name']);

        $timeline = $this->timeline->for($order);

        return new JsonResponse([
            'data' => $this->index->row($order, forStaff: false) + [
                'customer_phone' => $order->customer['mobile'] ?? null,
                'shipping_address' => $order->shipping_address,
                'cancellation_reason' => $order->cancellation_reason,
                'items' => $order->items->map(fn ($item) => [
                    'sku' => $item->sku,
                    'name' => $item->product_name,
                    'variant' => $item->variant_label,
                    'quantity' => $item->quantity,
                    'line_total' => $item->line_total->jsonSerialize(),
                ])->all(),
            ],
            // The Partner reads the whole timeline; they get no next-action button.
            'timeline' => ['next_action' => null] + $timeline,
            // Customer-safe: the courier, its reference and Banij's own status.
            'courier' => $this->courier->forPartner($order),
            // Every return and exchange asked for on this order (D35).
            'cases' => $this->cases->forOrder($order),
            'exchange_origin' => $this->links->exchangeOriginForPartner($order),
            'can' => [
                'advance' => false,
                'cancel' => StoreOrderStep::for($order->status) === StoreOrderStep::Pending && ! $timeline['cancelled'],
                'request_case' => $this->mayRequestCase($request, $order),
                'report_damage' => $this->mayReportDamage($request, $order),
            ],
        ]);
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
     * Whether this person may report a damaged item on this order: something on it
     * can still be reported, and they manage the store it came through.
     */
    protected function mayReportDamage(Request $request, Order $order): bool
    {
        $user = $request->user();
        $website = $order->website_id === null ? null : Website::query()->find($order->website_id);

        return $user instanceof User
            && $website !== null
            && $this->damageEligibility->for($order)['eligible']
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
