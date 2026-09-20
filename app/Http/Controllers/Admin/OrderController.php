<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Order\Actions\CancelUnpaidOrderByStaff;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Exceptions\OrderRefused;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\OrderStatusChange;
use App\Domain\Order\Queries\CodConfirmationState;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Concurrency\Exceptions\LockTimeout;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The platform's review of orders (§18.4, §18.5, P4-12).
 *
 * Reading every order is `order.view`; cancelling one nobody has paid for is
 * `order.edit`. Both are platform permissions the `Gate::before` refuses to a
 * business identity, so owning an order is never a way into this screen — the
 * account follows its own orders on its own page, without the staff notes shown
 * here.
 *
 * Held orders and payments waiting on reconciliation are shown for what they are,
 * with the reason recorded. Resolving them — finding the stock again or refunding —
 * belongs to the fulfilment and refund work that follows; nothing here pretends to.
 */
class OrderController extends Controller
{
    public const PER_PAGE = 25;

    public function __construct(
        protected CancelUnpaidOrderByStaff $cancel,
        protected CodConfirmationState $confirmations,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);

        Gate::forUser($actor)->authorize('viewAny', Order::class);

        $filters = $this->filters($request);
        $term = $filters['search'];

        $orders = Order::query()
            ->with(['businessAccount:id,name', 'payment:id,status', 'website:id,name'])
            ->when($filters['status'] !== null, fn (Builder $query) => $query->where('status', $filters['status']))
            ->when($filters['source'] !== null, fn (Builder $query) => $query->where('source', $filters['source']))
            ->when($term !== null, fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->where('reference', 'ilike', "%{$term}%")
                ->orWhere('storefront_order_reference', 'ilike', "%{$term}%")
                ->orWhereHas('businessAccount', fn (Builder $account) => $account->where('name', 'ilike', "%{$term}%"))
                ->orWhereHas('website', fn (Builder $website) => $website->where('name', 'ilike', "%{$term}%"))))
            // What somebody has to act on first: held orders, then waiting ones.
            ->orderByRaw("CASE status WHEN 'on_hold' THEN 0 WHEN 'payment_pending' THEN 1 ELSE 2 END")
            ->orderByDesc('placed_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Order $order) => [
                'id' => $order->public_id,
                'reference' => $order->reference,
                'account' => $order->businessAccount->name,
                'source' => $order->source->value,
                // Which shop took it, for an order that came through one (§18.5).
                'website' => $order->website?->name,
                'storefront_reference' => $order->storefront_order_reference,
                'status' => $order->status->value,
                'status_tone' => $order->status->tone(),
                'payment_status' => $order->payment?->status->value,
                'needs_attention' => $order->status === OrderStatus::OnHold
                    || ($order->payment?->status->needsReconciliation() ?? false),
                'total' => $order->total_minor->jsonSerialize(),
                'placed_at' => $order->placed_at->toIso8601String(),
            ]);

        return Inertia::render('admin/orders/index', [
            'orders' => $orders,
            'filters' => $filters,
            'statuses' => array_map(fn (OrderStatus $status) => $status->value, OrderStatus::cases()),
            'sources' => array_map(fn (OrderSource $source) => $source->value, OrderSource::cases()),
        ]);
    }

    public function show(Request $request, string $order): Response
    {
        $actor = $this->actor($request);

        Gate::forUser($actor)->authorize('viewAny', Order::class);

        $record = $this->order($order);
        $record->load(['businessAccount:id,name', 'placedBy:id,name', 'items.stockReservation', 'payment.invoice', 'statusHistory.changedBy:id,name', 'website:id,public_id,name,subdomain', 'websiteCustomer:id,public_id,mobile,is_guest']);

        $payment = $record->payment;

        return Inertia::render('admin/orders/show', [
            'order' => [
                'id' => $record->public_id,
                'reference' => $record->reference,
                'source' => $record->source->value,
                'status' => $record->status->value,
                'status_tone' => $record->status->tone(),
                'account' => $record->businessAccount->name,
                'placed_by' => $record->placedBy?->name,
                /*
                 * The shop it came through, and the customer as that shop's
                 * own record knows them (§18.5). The snapshot below is what
                 * the order says; this is who to look up.
                 */
                'website' => $record->website === null ? null : [
                    'id' => $record->website->public_id,
                    'name' => $record->website->name,
                ],
                'storefront_reference' => $record->storefront_order_reference,
                'website_customer' => $record->websiteCustomer === null ? null : [
                    'id' => $record->websiteCustomer->public_id,
                    'mobile' => $record->websiteCustomer->mobile,
                    'is_guest' => $record->websiteCustomer->is_guest,
                ],
                'placed_at' => $record->placed_at->toIso8601String(),
                'paid_at' => $record->paid_at?->toIso8601String(),
                'cancelled_at' => $record->cancelled_at?->toIso8601String(),
                'cancellation_reason' => $record->cancellation_reason,
                'held_at' => $record->held_at?->toIso8601String(),
                'hold_reason' => $record->hold_reason,
                'customer' => $record->customer,
                'addresses' => [
                    'billing' => $record->billing_address,
                    'shipping' => $record->shipping_address,
                ],
                'customer_note' => $record->customer_note,
                'intended_resale_channel' => $record->intended_resale_channel?->value,
                'coupon_code' => $record->coupon_code,
                'totals' => [
                    'subtotal' => $record->subtotal_minor->jsonSerialize(),
                    'discount' => $record->discount_minor->jsonSerialize(),
                    'delivery' => $record->delivery_minor->jsonSerialize(),
                    'tax' => $record->tax_minor->jsonSerialize(),
                    'tax_included' => $record->tax_included_minor->jsonSerialize(),
                    'total' => $record->total_minor->jsonSerialize(),
                ],
                'lines' => $record->items->map(fn (OrderItem $item) => [
                    'id' => $item->public_id,
                    'name' => $item->product_name,
                    'sku' => $item->sku,
                    'variant' => $item->variant_label,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price_minor->jsonSerialize(),
                    'discount' => $item->discount_minor->jsonSerialize(),
                    'tax' => $item->tax_minor->jsonSerialize(),
                    'total' => $item->line_total_minor->jsonSerialize(),
                    'reservation' => $item->stockReservation === null ? null : [
                        'reference' => $item->stockReservation->reference,
                        'status' => $item->stockReservation->status->value,
                        'expires_at' => $item->stockReservation->expires_at->toIso8601String(),
                    ],
                ])->all(),
                'payment' => $payment === null ? null : [
                    'reference' => $payment->reference,
                    'status' => $payment->status->value,
                    'method' => $record->isCashOnDelivery() ? 'cod' : 'online',
                    'gateway' => $payment->gateway,
                    'gateway_mode' => $payment->gateway_mode,
                    'expires_at' => $payment->expires_at?->toIso8601String(),
                    'completed_at' => $payment->completed_at?->toIso8601String(),
                    'reconciliation_reason' => $payment->reconciliation_reason,
                ],
                'invoice' => $payment?->invoice === null ? null : ['number' => $payment->invoice->number],
                /*
                 * A cash-on-delivery order's confirmation (§6.2, P6-10): where
                 * it stands, until when, whether a code is outstanding and how
                 * many wrong guesses have been used — which is what answers
                 * "the customer says their code does not work". The code
                 * itself is held hashed and is readable by nobody.
                 */
                'confirmation' => $this->confirmations->for($record, forStaff: true),
                'history' => $record->statusHistory->map(fn (OrderStatusChange $change) => [
                    'previous_status' => $change->previous_status?->value,
                    'new_status' => $change->new_status->value,
                    'at' => $change->changed_at->toIso8601String(),
                    'source' => $change->source->value,
                    'actor' => $change->changedBy?->name,
                    'reason' => $change->reason,
                    'internal_note' => $change->internal_note,
                ])->all(),
            ],
            'can' => [
                // Waiting for a payment, or for a cash-on-delivery customer to
                // confirm: nobody has paid for either (§18.4, P6-10).
                'cancel' => $actor->can('transition', $record)
                    && in_array($record->status, [OrderStatus::PaymentPending, OrderStatus::CustomerVerificationPending], true)
                    && $payment !== null
                    && $payment->status !== PaymentStatus::Pending
                    && ! $payment->status->isSettled(),
            ],
        ]);
    }

    public function cancel(Request $request, string $order): RedirectResponse
    {
        $actor = $this->actor($request);
        $record = $this->order($order);

        Gate::forUser($actor)->authorize('transition', $record);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:'.CancelUnpaidOrderByStaff::MINIMUM_REASON, 'max:1000'],
        ]);

        try {
            $this->cancel->handle($actor, $record, $validated['reason']);
        } catch (OrderRefused $refused) {
            throw ValidationException::withMessages(['reason' => $refused->getMessage()]);
        } catch (LockTimeout) {
            throw ValidationException::withMessages(['reason' => __('orders.refused.busy')]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('orders.admin.cancelled', ['reference' => $record->reference])]);

        return back();
    }

    /**
     * @return array{search: string|null, status: string|null, source: string|null}
     */
    protected function filters(Request $request): array
    {
        $search = trim($request->string('search')->toString());

        return [
            'search' => $search === '' ? null : mb_substr($search, 0, 120),
            'status' => OrderStatus::tryFrom($request->string('status')->toString())?->value,
            'source' => OrderSource::tryFrom($request->string('source')->toString())?->value,
        ];
    }

    protected function order(string $publicId): Order
    {
        /** @var Order $order */
        $order = Order::query()->where('public_id', $publicId)->firstOrFail();

        return $order;
    }

    protected function actor(Request $request): User
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);

        return $actor;
    }
}
