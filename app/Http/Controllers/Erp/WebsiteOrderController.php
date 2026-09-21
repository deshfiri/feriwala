<?php

namespace App\Http\Controllers\Erp;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Order\Actions\CancelOrderReturn;
use App\Domain\Order\Actions\CancelUnpaidOrder;
use App\Domain\Order\Actions\RequestOrderReturn;
use App\Domain\Order\Data\ReturnSubmission;
use App\Domain\Order\Enums\OrderPaymentState;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Enums\ReturnReason;
use App\Domain\Order\Enums\UnpaidOrderCancellation;
use App\Domain\Order\Exceptions\OrderRefused;
use App\Domain\Order\Exceptions\ReturnRefused;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\OrderReturn;
use App\Domain\Order\Models\OrderStatusChange;
use App\Domain\Order\Queries\CodConfirmationState;
use App\Domain\Order\Queries\ReturnEligibility;
use App\Domain\Order\Queries\ReturnPayload;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Queries\WebsiteOverview;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Concurrency\Exceptions\LockTimeout;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Builder as Query;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The orders a partner's own website took (§16.3, §18.4, P5-13, P6-8, P6-10).
 *
 * **Self-scoped by construction.** Every query starts from the website, and the
 * website is resolved through the account the signed-in person belongs to —
 * another partner's website, and every order on it, is a 404 rather than a
 * refusal, so this cannot be used to find out whose it is.
 *
 * What the owner sees is their sale: who bought, where it goes, what it cost,
 * where the payment stands, how long the stock is held, and the timeline in the
 * words the customer was given. Not the gateway's own identifiers, not a staff
 * note, not the reason Feriwala put an order on hold.
 */
class WebsiteOrderController extends Controller
{
    use ResolvesBusinessAccount;

    public const PER_PAGE = 20;

    public function __construct(
        protected WebsiteOverview $websites,
        protected CancelUnpaidOrder $cancellations,
        protected CodConfirmationState $confirmations,
        protected ReturnEligibility $eligibility,
        protected ReturnPayload $returns,
        protected RequestOrderReturn $returnRequests,
        protected CancelOrderReturn $returnCancellations,
    ) {}

    public function index(Request $request, string $website): Response
    {
        $record = $this->websiteFor($request, $website);
        $filters = $this->filters($request);
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
            ->withQueryString()
            ->through(fn (Order $order) => $this->row($order));

        return Inertia::render('websites/orders', [
            'website' => ['id' => $record->public_id, 'name' => $record->name],
            'orders' => $orders,
            'filters' => $filters,
            'statuses' => $this->statuses($record),
        ]);
    }

    public function show(Request $request, string $website, string $order): Response
    {
        $record = $this->websiteFor($request, $website);
        $found = $this->find($record, $order);
        $found->load(['items.stockReservation', 'payment', 'statusHistory']);

        $manages = (bool) $request->user()?->can('manage', $record);
        $returnable = $this->eligibility->for($found);

        return Inertia::render('websites/order', [
            'website' => ['id' => $record->public_id, 'name' => $record->name],
            'order' => $this->detail($found),
            /*
             * What may still come back, and every return asked for (P6-12) —
             * in the reader's own language, and nothing of where Feriwala keeps
             * the goods or what it did with them.
             */
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
     * Ask for a return on the customer's behalf (§18.2, P6-12).
     *
     * The owner taking the call their customer made: the same request, the same
     * checks and the same idempotency as one arriving from the storefront.
     */
    public function requestReturn(Request $request, string $website, string $order): RedirectResponse
    {
        $record = $this->websiteFor($request, $website);
        $found = $this->find($record, $order);

        abort_if(! ($request->user()?->can('manage', $record) ?? false), 403);

        $validated = $request->validate([
            'reason' => ['required', Rule::in(ReturnReason::values())],
            'note' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array'],
            'lines.*' => ['integer', 'min:0'],
            'idempotency_key' => ['required', 'string', 'max:64'],
        ]);

        $lines = [];

        foreach ($validated['lines'] as $sku => $quantity) {
            if ((int) $quantity > 0) {
                $lines[] = ['sku' => (string) $sku, 'quantity' => (int) $quantity];
            }
        }

        try {
            $this->returnRequests->handle($found, new ReturnSubmission(
                reason: ReturnReason::from($validated['reason']),
                lines: $lines,
                customerNote: $validated['note'] ?? null,
                idempotencyKey: 'account:'.$validated['idempotency_key'],
            ), OrderStatusChangeSource::Account, $this->person($request));
        } catch (ReturnRefused $refused) {
            throw ValidationException::withMessages(['lines' => $refused->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('website.orders.returns.flash.requested')]);

        return to_route('websites.orders.show', [$record->public_id, $found->public_id]);
    }

    /**
     * Withdraw a return before any goods have come back.
     */
    public function cancelReturn(Request $request, string $website, string $order, string $return): RedirectResponse
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

        Inertia::flash('toast', ['type' => 'success', 'message' => __('website.orders.returns.flash.cancelled')]);

        return to_route('websites.orders.show', [$record->public_id, $found->public_id]);
    }

    /**
     * Cancel an order the customer has not paid for (§18.4).
     *
     * The same cancellation every unpaid order goes through: the payment is
     * closed, the stock goes back once, and the shop is told.
     */
    public function cancel(Request $request, string $website, string $order): RedirectResponse
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

        Inertia::flash('toast', ['type' => 'success', 'message' => __('website.orders.flash.cancelled')]);

        return to_route('websites.orders.show', [$record->public_id, $found->public_id]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(Order $order): array
    {
        return [
            'id' => $order->public_id,
            'reference' => $order->reference,
            'storefront_reference' => $order->storefront_order_reference,
            'customer' => $order->customer['name'] ?? null,
            'status' => $order->status->value,
            'status_label' => $order->status->label(),
            'status_tone' => $order->status->tone(),
            'payment_state' => OrderPaymentState::of($order->payment)?->value,
            'payment_method' => $order->isCashOnDelivery() ? 'cod' : 'online',
            'total' => $order->total_minor->jsonSerialize(),
            'placed_at' => $order->placed_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function detail(Order $order): array
    {
        $payment = $order->payment;
        $customer = $order->customer;

        return [
            ...$this->row($order),
            'customer_details' => [
                'name' => $customer['name'] ?? null,
                'mobile' => $customer['mobile'] ?? null,
                'email' => $customer['email'] ?? null,
                'is_guest' => (bool) ($customer['is_guest'] ?? true),
            ],
            'shipping_address' => $order->shipping_address,
            'billing_address' => $order->billing_address,
            'customer_note' => $order->customer_note,
            'paid_at' => $order->paid_at?->toIso8601String(),
            'cancelled_at' => $order->cancelled_at?->toIso8601String(),
            'totals' => [
                'subtotal' => $order->subtotal_minor->jsonSerialize(),
                'discount' => $order->discount_minor->jsonSerialize(),
                'delivery' => $order->delivery_minor->jsonSerialize(),
                'tax' => $order->tax_minor->jsonSerialize(),
                'total' => $order->total_minor->jsonSerialize(),
            ],
            'lines' => $order->items->map(fn (OrderItem $item) => [
                'id' => $item->public_id,
                'name' => $item->product_name,
                'sku' => $item->sku,
                'variant' => $item->variant_label,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price_minor->jsonSerialize(),
                'total' => $item->line_total_minor->jsonSerialize(),
                // How long the stock is held, and nothing about where it is.
                'reservation' => $item->stockReservation === null ? null : [
                    'status' => $item->stockReservation->status->value,
                    'status_label' => __('inventory.reservation_statuses.'.$item->stockReservation->status->value),
                    'expires_at' => $item->stockReservation->expires_at->toIso8601String(),
                ],
            ])->all(),
            'payment' => $payment === null ? null : [
                'state' => OrderPaymentState::of($payment)?->value,
                'method' => $order->isCashOnDelivery() ? 'cod' : 'online',
                'expires_at' => $payment->expires_at?->toIso8601String(),
                'completed_at' => $payment->completed_at?->toIso8601String(),
            ],
            /*
             * Where the customer's confirmation stands, for a sale paid on
             * delivery (§6.2). The window and whether a code is outstanding —
             * never the code, and never how many guesses are left.
             */
            'confirmation' => $this->confirmations->for($order),
            // The customer's own account of what happened, as the storefront reads it.
            'timeline' => $order->statusHistory
                ->filter(fn (OrderStatusChange $change) => $change->public_note !== null)
                ->map(fn (OrderStatusChange $change) => [
                    'status' => $change->new_status->value,
                    'status_label' => $change->new_status->label(),
                    'at' => $change->changed_at->toIso8601String(),
                    'note' => (string) __($change->public_note),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Only the statuses this shop's orders have actually been in.
     *
     * @return array<int, array{value: string, label: string}>
     */
    protected function statuses(Website $website): array
    {
        return $this->scoped($website)
            ->distinct()
            ->orderBy('status')
            ->pluck('status')
            ->map(fn (OrderStatus $status) => [
                'value' => $status->value,
                'label' => $status->label(),
            ])
            ->all();
    }

    /**
     * @return array{search: string|null, status: string|null}
     */
    protected function filters(Request $request): array
    {
        $search = trim($request->string('search')->toString());

        return [
            'search' => $search === '' ? null : mb_substr($search, 0, 120),
            'status' => OrderStatus::tryFrom($request->string('status')->toString())?->value,
        ];
    }

    /**
     * Whether the owner may still call this order off.
     *
     * Waiting for a payment, or waiting for a cash-on-delivery customer to
     * confirm: in both, nobody has paid and the stock is only being held
     * (§18.4, P6-10). A paid order is refunded, not cancelled.
     */
    protected function cancellable(Order $order): bool
    {
        return in_array($order->status, [OrderStatus::PaymentPending, OrderStatus::CustomerVerificationPending], true)
            && $order->payment !== null
            && ! $order->payment->status->isSettled();
    }

    /**
     * @return Query<Order>
     */
    protected function scoped(Website $website): Query
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

    protected function websiteFor(Request $request, string $website): Website
    {
        $record = $this->websites->findForAccount($this->businessAccountFor($request), $website);

        abort_if($record === null, 404);

        return $record;
    }

    protected function person(Request $request): User
    {
        $user = $request->user();

        abort_if(! $user instanceof User, 403);

        return $user;
    }
}
