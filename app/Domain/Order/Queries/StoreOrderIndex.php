<?php

namespace App\Domain\Order\Queries;

use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Order\Enums\OrderCourierStatus;
use App\Domain\Order\Enums\OrderDeliveryStatus;
use App\Domain\Order\Enums\OrderPaymentState;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\StoreOrderStep;
use App\Domain\Order\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The Store Orders list, for staff (every order) or for one Partner (only theirs).
 *
 * The scope is a query concern: a Partner's list is built from their own account
 * and nothing in the request can widen it, so another Partner's order is not
 * reachable by a filter, a URL or a changed parameter (§31.3). Staff may narrow
 * by Partner and Storefront as well.
 */
class StoreOrderIndex
{
    public const PER_PAGE = 25;

    /**
     * @return Builder<Order>
     */
    public function query(Request $request, ?int $accountId = null): Builder
    {
        $query = Order::query()
            ->where('source', OrderSource::Website->value)
            ->with(['businessAccount:id,public_id,name', 'website:id,public_id,name,subdomain', 'payment:id,status,gateway'])
            ->when($accountId !== null, fn (Builder $q) => $q->where('business_account_id', $accountId));

        if ($accountId === null && $request->filled('partner')) {
            $query->whereHas('businessAccount', fn (Builder $q) => $q->where('public_id', $request->string('partner')->toString()));
        }

        if ($request->filled('storefront')) {
            $query->whereHas('website', fn (Builder $q) => $q->where('public_id', $request->string('storefront')->toString()));
        }

        if ($request->filled('step') && ($step = StoreOrderStep::tryFrom($request->string('step')->toString())) !== null) {
            $query->whereIn('status', collect(OrderStatus::cases())
                ->filter(fn (OrderStatus $status) => StoreOrderStep::for($status) === $step)
                ->map(fn (OrderStatus $status) => $status->value)
                ->all());
        }

        if ($request->filled('payment')) {
            $payment = $request->string('payment')->toString();

            // The filter matches what the row shows, a delivered COD order counting as paid.
            $query->where(fn (Builder $q) => $payment === PaymentStatus::Paid->value
                ? $q->whereHas('payment', fn (Builder $p) => $p->where('status', $payment))
                    ->orWhere(fn (Builder $cod) => $this->deliveredCashOnDelivery($cod))
                : $q->whereHas('payment', fn (Builder $p) => $p->where('status', $payment))
                    ->whereNot(fn (Builder $cod) => $this->deliveredCashOnDelivery($cod)));
        }

        foreach (['from' => '>=', 'to' => '<='] as $key => $operator) {
            if ($request->filled($key) && strtotime($request->string($key)->toString()) !== false) {
                $day = CarbonImmutable::parse($request->string($key)->toString());
                $query->where('placed_at', $operator, $key === 'from' ? $day->startOfDay() : $day->endOfDay());
            }
        }

        if ($request->filled('search')) {
            $term = $request->string('search')->toString();
            $query->where(fn (Builder $q) => $q->where('reference', 'ilike', "%{$term}%")->orWhere('storefront_order_reference', 'ilike', "%{$term}%"));
        }

        return $query->orderByDesc('placed_at')->orderByDesc('id');
    }

    /**
     * A cash-on-delivery order (no gateway) whose parcel reached the customer,
     * still unsettled on the payment record: the courier collected the cash.
     *
     * @param  Builder<Order>  $query
     */
    protected function deliveredCashOnDelivery(Builder $query): void
    {
        $query->whereHas('payment', fn (Builder $p) => $p->whereNull('gateway')
            ->whereIn('status', [PaymentStatus::Draft->value, PaymentStatus::Initiated->value, PaymentStatus::Pending->value]))
            ->where(fn (Builder $delivered) => $delivered
                ->where('delivery_status', OrderDeliveryStatus::Delivered->value)
                ->orWhereIn('status', [OrderStatus::Delivered->value, OrderStatus::Completed->value]));
    }

    /**
     * The one status a Partner reads: the order's own step until it leaves the
     * warehouse, then wherever the courier and delivery have taken it, each
     * update replacing the last as it arrives. An exception (cancelled, failed,
     * returned, on hold) wins over progress.
     *
     * @return array{value: string, label: string, tone: string}
     */
    public function progress(Order $order): array
    {
        $courier = $order->courier_status;
        $delivery = $order->delivery_status;
        $step = StoreOrderStep::for($order->status);

        [$value, $tone] = match (true) {
            $order->status === OrderStatus::Cancelled || $order->cancelled_at !== null => ['cancelled', 'danger'],
            in_array($delivery, [OrderDeliveryStatus::ReturnRequested, OrderDeliveryStatus::Returned, OrderDeliveryStatus::Refunded], true),
            in_array($order->status, [OrderStatus::ReturnRequested, OrderStatus::ReturnApproved, OrderStatus::Returning, OrderStatus::Returned], true),
            $courier === OrderCourierStatus::ReturnedToOrigin => ['returned', 'warning'],
            $order->status === OrderStatus::Completed => ['completed', 'success'],
            $delivery === OrderDeliveryStatus::Delivered, $order->status === OrderStatus::Delivered, $courier === OrderCourierStatus::Delivered => ['delivered', 'success'],
            $delivery === OrderDeliveryStatus::FailedDelivery, $order->status === OrderStatus::DeliveryFailed, $courier === OrderCourierStatus::FailedDelivery => ['failed_delivery', 'danger'],
            $order->status === OrderStatus::OnHold, $delivery === OrderDeliveryStatus::OnHold => ['on_hold', 'warning'],
            $delivery === OrderDeliveryStatus::OutForDelivery => ['out_for_delivery', 'info'],
            $delivery === OrderDeliveryStatus::InTransit, $courier === OrderCourierStatus::InTransit, $order->status === OrderStatus::InTransit => ['in_transit', 'info'],
            $courier === OrderCourierStatus::PickedUp => ['picked_up', 'info'],
            in_array($courier, [OrderCourierStatus::Assigned, OrderCourierStatus::PickupRequested], true) => ['courier_booked', 'info'],
            $step !== null => [$step->value, 'info'],
            default => ['on_hold', 'warning'],
        };

        return ['value' => $value, 'label' => (string) __('store_orders.progress.'.$value), 'tone' => $tone];
    }

    /**
     * One row of the list. The partner and storefront are the order's own
     * snapshot-time records, never a live lookup of anything else.
     *
     * @return array<string, mixed>
     */
    public function row(Order $order, bool $forStaff): array
    {
        $step = StoreOrderStep::for($order->status);

        return [
            'id' => $order->public_id,
            'reference' => $order->reference,
            'storefront_reference' => $order->storefront_order_reference,
            'storefront' => ['id' => $order->website?->public_id, 'name' => $order->website?->name],
            'partner' => $forStaff ? ['id' => $order->businessAccount->public_id, 'name' => $order->businessAccount->name] : null,
            'customer' => $order->customer['name'] ?? null,
            'step' => $step?->value,
            'step_label' => $step?->label() ?? __('store_orders.cancelled'),
            'status' => $order->status->value,
            'fulfilment' => $order->fulfillment_status->value,
            'courier' => $order->courier_status->value,
            'delivery' => $order->delivery_status->value,
            // A delivered cash-on-delivery order is paid: the courier collected the cash.
            'progress' => $this->progress($order),
            'payment' => OrderPaymentState::forOrder($order) === OrderPaymentState::Paid
                ? PaymentStatus::Paid->value
                : $order->payment?->status->value,
            'total' => $order->total->jsonSerialize(),
            'placed_at' => $order->placed_at->toIso8601String(),
        ];
    }
}
