<?php

namespace App\Domain\Order\Actions;

use App\Domain\Order\Enums\OrderDeliveryStatus;
use App\Domain\Order\Enums\OrderFulfillmentStatus;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Models\Order;
use App\Support\StatusHistory\StatusChange;

/**
 * Keep the order's own status in step with its fulfilment and delivery axes,
 * wherever {@see OrderStatus} already has a matching case (§18, §20, §21).
 *
 * Called at the end of every {@see AdvanceOrderFulfilmentStatus}/
 * {@see AdvanceOrderDeliveryStatus} move, in the same transaction. Nothing
 * before this batch ever moved `status` through its picking/packing/shipped
 * states (P6.B/P6.C were not built), so this is a best-effort sync, not a
 * hard requirement: every check goes through {@see Order::canTransitionTo()}
 * first and is silently skipped when the order's own status is not currently
 * somewhere the move is legal from, rather than forcing an illegal jump or
 * throwing. `OrderFulfillmentStatus::PartiallyFulfilled` has no matching
 * `OrderStatus` case at all and is never rolled up — it is a state staff
 * chooses by hand, from what they see on each line.
 */
class RollUpOrderStatus
{
    public function handle(Order $order): void
    {
        if ($order->delivery_status === OrderDeliveryStatus::Delivered
            && $order->canTransitionTo(OrderStatus::Delivered)) {
            $order->moveTo(
                OrderStatus::Delivered,
                StatusChange::bySystem('The order reached Delivered on its delivery status.'),
                OrderStatusChangeSource::System,
            );
        }

        if ($order->fulfillment_status === OrderFulfillmentStatus::Fulfilled
            && $order->delivery_status === OrderDeliveryStatus::Delivered
            && ($order->payment?->status->isSettled() ?? false)
            && $order->canTransitionTo(OrderStatus::Completed)) {
            $order->moveTo(
                OrderStatus::Completed,
                StatusChange::bySystem('Fulfilled, delivered and paid.'),
                OrderStatusChangeSource::System,
            );
        }
    }
}
