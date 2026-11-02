<?php

namespace App\Http\Controllers\Api\Mobile\V1\Websites;

use App\Domain\Order\Actions\CancelOrderReturn;
use App\Domain\Order\Actions\CancelUnpaidOrder;
use App\Domain\Order\Actions\RequestOrderReturn;
use App\Domain\Order\Data\ReturnSubmission;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Enums\ReturnReason;
use App\Domain\Order\Enums\UnpaidOrderCancellation;
use App\Domain\Order\Exceptions\OrderRefused;
use App\Domain\Order\Exceptions\ReturnRefused;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderReturn;
use App\Domain\Order\Queries\CodConfirmationState;
use App\Domain\Order\Queries\OrderTimeline;
use App\Domain\Order\Queries\ReturnEligibility;
use App\Domain\Order\Queries\ReturnPayload;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Queries\WebsiteOverview;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Controllers\Api\Mobile\V1\Websites\Concerns\ResolvesOwnWebsite;
use App\Http\Requests\Mobile\Websites\RequestWebsiteOrderReturnRequest;
use App\Http\Requests\Mobile\Websites\WebsiteOrderListRequest;
use App\Http\Resources\Mobile\Websites\WebsiteOrderDetailResource;
use App\Http\Resources\Mobile\Websites\WebsiteOrderResource;
use App\Support\Concurrency\Exceptions\LockTimeout;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * The orders a partner's own website took (§16.3, §18.4).
 *
 * **Self-scoped by construction.** Every query starts from the website, and the
 * website is resolved through the signed-in person's own account — another
 * partner's website, and every order on it, is a `404`. What the owner sees is
 * their sale, in the words the customer was given: not the gateway's identifiers,
 * not a staff note, not the reason Feriwala put an order on hold.
 */
class WebsiteOrderController extends MobileController
{
    use ResolvesOwnWebsite;

    public const PER_PAGE = 20;

    public function __construct(
        protected WebsiteOverview $overview,
        protected CancelUnpaidOrder $cancellations,
        protected CodConfirmationState $confirmations,
        protected ReturnEligibility $eligibility,
        protected ReturnPayload $returns,
        protected RequestOrderReturn $returnRequests,
        protected CancelOrderReturn $returnCancellations,
        protected OrderTimeline $timeline,
    ) {}

    /**
     * List website orders
     *
     * The orders this website took, newest first, 20 to a page. An unknown `status` is
     * ignored. `statuses` lists only the statuses this shop's orders have been in, and
     * `filters` echoes what was applied.
     *
     * @response array{data: list<WebsiteOrderResource>, links: array<string, string|null>, meta: array<string, mixed>, website: array{id: string, name: string}, filters: array{search: string|null, status: string|null}, statuses: list<array{value: string, label: string}>}
     */
    public function index(WebsiteOrderListRequest $request, string $website): AnonymousResourceCollection
    {
        $record = $this->websiteFor($request, $website);

        $search = trim($request->string('search')->toString());
        $filters = [
            'search' => $search === '' ? null : mb_substr($search, 0, 120),
            'status' => OrderStatus::tryFrom($request->string('status')->toString())?->value,
        ];
        $term = $filters['search'];

        $orders = $this->scoped($record)
            ->when($filters['status'] !== null, fn (Builder $query) => $query->where('status', $filters['status']))
            ->when($term !== null, fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->where('reference', 'ilike', "%{$term}%")
                ->orWhere('storefront_order_reference', 'ilike', "%{$term}%")
                ->orWhereRaw("customer->>'name' ilike ?", ["%{$term}%"])))
            ->with('payment:id,status,gateway')
            ->orderByDesc('placed_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return WebsiteOrderResource::collection($orders)->additional([
            'website' => ['id' => $record->public_id, 'name' => $record->name],
            'filters' => $filters,
            'statuses' => $this->scoped($record)
                ->distinct()
                ->orderBy('status')
                ->pluck('status')
                ->map(fn (OrderStatus $status) => ['value' => $status->value, 'label' => $status->label()])
                ->all(),
        ]);
    }

    /**
     * Website order detail
     *
     * One order in full: the customer, addresses, totals, lines, payment, the three
     * status tracks, parcels with courier events and the customer-facing timeline.
     * `history` is everything recorded about it, oldest first, with no platform notes.
     * `returnable` says what may still come back and `returns` lists every return asked
     * for. `can` says what this person may do (cancel an unpaid order, ask for a return
     * for the customer — both need the right to manage the website). Another account's
     * website or order is `404`.
     *
     * @response array{data: WebsiteOrderDetailResource, confirmation: array<string, mixed>|null, history: list<array<string, mixed>>, returnable: array{eligible: bool, refusal: mixed, window_closes_at: string|null, lines: list<array<string, mixed>>}, returns: list<array<string, mixed>>, reasons: list<string>, can: array{cancel: bool, request_return: bool}}
     */
    public function show(Request $request, string $website, string $order): JsonResponse
    {
        $record = $this->websiteFor($request, $website);

        return $this->orderResponse($request, $record, $this->find($record, $order));
    }

    /**
     * Cancel an unpaid order
     *
     * Cancels an order the customer has not paid for — waiting for payment, or for a
     * cash-on-delivery confirmation. The payment is closed, the stock goes back once and
     * the shop is told. Returns the order as `show` does. `403` without the right to
     * manage the website; `422` when the order is not cancellable (a paid order is
     * refunded, not cancelled).
     *
     * @response array{data: WebsiteOrderDetailResource, confirmation: array<string, mixed>|null, history: list<array<string, mixed>>, returnable: array{eligible: bool, refusal: mixed, window_closes_at: string|null, lines: list<array<string, mixed>>}, returns: list<array<string, mixed>>, reasons: list<string>, can: array{cancel: bool, request_return: bool}}
     */
    public function cancel(Request $request, string $website, string $order): JsonResponse
    {
        $record = $this->websiteFor($request, $website);
        $found = $this->find($record, $order);

        abort_if(! ($request->user()?->can('manage', $record) ?? false), 403);

        try {
            $this->cancellations->handle($found, UnpaidOrderCancellation::ByAccount, $this->person($request));
        } catch (OrderRefused $refused) {
            throw ValidationException::withMessages(['order' => $refused->getMessage()]);
        } catch (LockTimeout) {
            throw ValidationException::withMessages(['order' => __('orders.refused.busy')]);
        }

        return $this->orderResponse($request, $record, $found->refresh());
    }

    /**
     * Request a return for a customer
     *
     * Asks for a return on the customer's behalf: the same request, checks and
     * idempotency as one arriving from the storefront. `lines` is quantity by SKU. A
     * repeat with the same `idempotency_key` returns the same return (`200`); a new one is
     * `201`. `403` without the right to manage the website; `422` when the order or a
     * line cannot be returned.
     *
     * @response array{data: array<string, mixed>}
     */
    public function requestReturn(RequestWebsiteOrderReturnRequest $request, string $website, string $order): JsonResponse
    {
        $record = $this->websiteFor($request, $website);
        $found = $this->find($record, $order);

        abort_if(! ($request->user()?->can('manage', $record) ?? false), 403);

        $validated = $request->validated();

        $lines = [];

        foreach ($validated['lines'] as $sku => $quantity) {
            if ((int) $quantity > 0) {
                $lines[] = ['sku' => (string) $sku, 'quantity' => (int) $quantity];
            }
        }

        try {
            [$case, $created] = $this->returnRequests->handle($found, new ReturnSubmission(
                reason: ReturnReason::from($validated['reason']),
                lines: $lines,
                customerNote: $validated['note'] ?? null,
                idempotencyKey: 'account:'.$validated['idempotency_key'],
            ), OrderStatusChangeSource::Account, $this->person($request));
        } catch (ReturnRefused $refused) {
            throw ValidationException::withMessages(['lines' => $refused->getMessage()]);
        }

        return new JsonResponse(['data' => $this->returns->for($case, app()->getLocale())], $created ? 201 : 200);
    }

    /**
     * Withdraw a return
     *
     * Withdraws a return before any goods have come back and returns the order as `show`
     * does. `403` without the right to manage the website, `404` for a return that is not
     * on this order, `422` when it can no longer be withdrawn.
     *
     * @response array{data: WebsiteOrderDetailResource, confirmation: array<string, mixed>|null, history: list<array<string, mixed>>, returnable: array{eligible: bool, refusal: mixed, window_closes_at: string|null, lines: list<array<string, mixed>>}, returns: list<array<string, mixed>>, reasons: list<string>, can: array{cancel: bool, request_return: bool}}
     */
    public function cancelReturn(Request $request, string $website, string $order, string $return): JsonResponse
    {
        $record = $this->websiteFor($request, $website);
        $found = $this->find($record, $order);

        abort_if(! ($request->user()?->can('manage', $record) ?? false), 403);

        /** @var OrderReturn $returned */
        $returned = OrderReturn::query()
            ->where('order_id', $found->id)
            ->where('public_id', $return)
            ->firstOrFail();

        try {
            $this->returnCancellations->handle($returned, OrderStatusChangeSource::Account, $this->person($request), 'Withdrawn by the shop.');
        } catch (ReturnRefused $refused) {
            throw ValidationException::withMessages(['return' => $refused->getMessage()]);
        }

        return $this->orderResponse($request, $record, $found->refresh());
    }

    protected function orderResponse(Request $request, Website $record, Order $found): JsonResponse
    {
        $found->load(['items.stockReservation', 'payment', 'statusHistory', 'shipments.courierProvider', 'shipments.trackingEvents']);

        $manages = (bool) $request->user()?->can('manage', $record);
        $returnable = $this->eligibility->for($found);

        return new JsonResponse([
            'data' => (new WebsiteOrderDetailResource($found))->resolve($request),
            'confirmation' => $this->confirmations->for($found),
            'history' => $this->timeline->forOrder($found),
            'returnable' => [
                'eligible' => $returnable['eligible'],
                'refusal' => $returnable['refusal'],
                'window_closes_at' => $returnable['window_closes_at'],
                'lines' => $returnable['lines'],
            ],
            'returns' => $this->eligibility->existingFor($found)
                ->map(fn (OrderReturn $return) => [
                    ...$this->returns->for($return, app()->getLocale()),
                    'can_cancel' => $manages && $return->status->isOpen(),
                ])
                ->values()
                ->all(),
            'reasons' => ReturnReason::values(),
            'can' => [
                'cancel' => $this->cancellable($found) && $manages,
                'request_return' => $manages && $returnable['eligible'],
            ],
        ]);
    }

    /**
     * Whether the owner may still call this order off: nobody has paid and the
     * stock is only being held (§18.4).
     */
    protected function cancellable(Order $order): bool
    {
        return in_array($order->status, [OrderStatus::PaymentPending, OrderStatus::CustomerVerificationPending], true)
            && $order->payment !== null
            && ! $order->payment->status->isSettled();
    }

    /**
     * @return Builder<Order>
     */
    protected function scoped(Website $website): Builder
    {
        return Order::query()
            ->where('source', OrderSource::Website)
            ->where('website_id', $website->id);
    }

    protected function find(Website $website, string $publicId): Order
    {
        /** @var Order|null $order */
        $order = $this->scoped($website)->where('public_id', $publicId)->first();

        abort_if($order === null, 404);

        return $order;
    }
}
