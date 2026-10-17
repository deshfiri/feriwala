<?php

namespace App\Domain\Order\Queries;

use App\Domain\Order\Enums\OrderCourierStatus;
use App\Domain\Order\Enums\OrderDeliveryStatus;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\StoreOrderAction;
use App\Domain\Order\Enums\StoreOrderStep;
use App\Domain\Order\Models\Order;

/**
 * What a Store Order's page shows: the business sequence, who and when for each
 * step it has passed, the one it is at, and the courier steps after dispatch
 * (D34).
 *
 * Read from the order's own typed histories — nothing here is stored or
 * editable. Payment, cancellation and return are *not* steps of this sequence and
 * are reported beside it, so an exception never pretends a later step happened.
 */
class StoreOrderTimeline
{
    /**
     * @return array{
     *     steps: list<array{key: string, label: string, state: string, at: string|null, by: string|null}>,
     *     next_action: array{key: string, label: string}|null,
     *     exception: string|null,
     *     cancelled: bool,
     *     payment: string|null
     * }
     */
    public function for(Order $order): array
    {
        $order->loadMissing(['statusHistory.changedBy', 'courierStatusHistory.changedBy', 'deliveryStatusHistory.changedBy', 'payment']);

        $reached = [];

        foreach ($order->statusHistory->sortBy('id') as $change) {
            $step = StoreOrderStep::for($change->new_status);

            if ($step !== null) {
                $reached[$step->value] ??= $this->mark($change->changed_at->toIso8601String(), $change->changedBy?->name, $change->source->value);
            }

            if ($change->new_status === OrderStatus::Paid) {
                $reached['confirmed'] ??= $this->mark($change->changed_at->toIso8601String(), null, $change->source->value);
            }
        }

        $courier = $this->courierSteps($order);
        $current = StoreOrderStep::for($order->status);
        $cancelled = in_array($order->status, [OrderStatus::Cancelled, OrderStatus::RefundPending, OrderStatus::Refunded, OrderStatus::PartiallyRefunded], true)
            && $order->cancelled_at !== null;

        $steps = [];
        $order_of_steps = [
            StoreOrderStep::Pending, StoreOrderStep::Confirmed, StoreOrderStep::Processing,
            StoreOrderStep::Packing, StoreOrderStep::Packed, StoreOrderStep::Dispatched,
        ];

        foreach ($order_of_steps as $step) {
            $steps[] = $this->step($step->value, $step->label(), $reached[$step->value] ?? null, $current === $step && ! $cancelled);
        }

        // Courier steps appear only once the order has been dispatched.
        if (isset($reached['dispatched'])) {
            foreach ($courier as $key => [$label, $mark]) {
                $steps[] = $this->step($key, $label, $mark, false);
            }
        }

        $steps[] = $this->step('delivered', StoreOrderStep::Delivered->label(), $reached['delivered'] ?? null, $current === StoreOrderStep::Delivered);

        if (isset($reached['completed'])) {
            $steps[] = $this->step('completed', StoreOrderStep::Completed->label(), $reached['completed'], $current === StoreOrderStep::Completed);
        }

        // The first step not yet reached after the last reached one is the one
        // in progress, when nothing marks itself current.
        $next = $cancelled ? null : StoreOrderAction::nextFor($order->status);

        return [
            'steps' => $steps,
            'next_action' => $next === null ? null : ['key' => $next->value, 'label' => $next->label()],
            'exception' => $this->exception($order),
            'cancelled' => $cancelled,
            'payment' => $order->payment?->status->value,
        ];
    }

    /**
     * @return array{at: string, by: string|null, source: string}
     */
    protected function mark(string $at, ?string $by, string $source): array
    {
        return ['at' => $at, 'by' => $by, 'source' => $source];
    }

    /**
     * @param  array{at: string, by: string|null, source: string}|null  $mark
     * @return array{key: string, label: string, state: string, at: string|null, by: string|null}
     */
    protected function step(string $key, string $label, ?array $mark, bool $current): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'state' => $current ? 'current' : ($mark !== null ? 'done' : 'upcoming'),
            'at' => $mark['at'] ?? null,
            'by' => $mark === null ? null : ($mark['by'] ?? (string) __('store_orders.by.'.($mark['source'] === 'staff' ? 'staff' : 'system'))),
        ];
    }

    /**
     * The courier and delivery steps reached so far, from their own histories.
     *
     * @return array<string, array{0: string, 1: array{at: string, by: string|null, source: string}|null}>
     */
    protected function courierSteps(Order $order): array
    {
        $found = ['courier_booked' => null, 'picked_up' => null, 'in_transit' => null, 'out_for_delivery' => null];

        foreach ($order->courierStatusHistory->sortBy('id') as $change) {
            $key = match ($change->new_status) {
                OrderCourierStatus::Assigned, OrderCourierStatus::PickupRequested => 'courier_booked',
                OrderCourierStatus::PickedUp => 'picked_up',
                OrderCourierStatus::InTransit => 'in_transit',
                default => null,
            };

            if ($key !== null) {
                $found[$key] ??= $this->mark($change->changed_at->toIso8601String(), $change->changedBy?->name, $change->source->value);
            }
        }

        foreach ($order->deliveryStatusHistory->sortBy('id') as $change) {
            $key = match ($change->new_status) {
                OrderDeliveryStatus::InTransit => 'in_transit',
                OrderDeliveryStatus::OutForDelivery => 'out_for_delivery',
                default => null,
            };

            if ($key !== null) {
                $found[$key] ??= $this->mark($change->changed_at->toIso8601String(), $change->changedBy?->name, $change->source->value);
            }
        }

        $labels = ['courier_booked' => 'Courier Booked', 'picked_up' => 'Picked Up', 'in_transit' => 'In Transit', 'out_for_delivery' => 'Out for Delivery'];
        $steps = [];

        foreach ($labels as $key => $label) {
            $steps[$key] = [__('store_orders.courier.'.$key), $found[$key]];
        }

        return $steps;
    }

    /**
     * An exception to the sequence, named — never shown as progress.
     */
    protected function exception(Order $order): ?string
    {
        return match (true) {
            $order->status === OrderStatus::DeliveryFailed || $order->courier_status === OrderCourierStatus::FailedDelivery => 'delivery_failed',
            $order->courier_status === OrderCourierStatus::ReturnedToOrigin => 'returned_to_origin',
            $order->status === OrderStatus::OnHold => 'on_hold',
            in_array($order->status, [OrderStatus::ReturnRequested, OrderStatus::ReturnApproved, OrderStatus::Returning, OrderStatus::Returned], true) => 'return',
            default => null,
        };
    }
}
