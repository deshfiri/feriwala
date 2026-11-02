<?php

namespace App\Domain\Order;

use App\Domain\Order\Enums\OrderCourierStatus;
use App\Domain\Order\Enums\OrderDeliveryStatus;
use App\Domain\Order\Enums\OrderFulfillmentStatus;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Models\Order;

/**
 * Delivery and courier start only once the goods are packed.
 *
 * Fulfilment, delivery and the courier are separate state machines, but they
 * are not independent: nothing can be handed to a courier that has not been
 * prepared. So **starting** either track — the first move out of "not shipped"
 * or "no courier", or assigning a courier again — needs fulfilment to be Ready
 * for dispatch (or Fulfilled, which follows the handover).
 *
 * What it does not do: hold or cancel are always allowed, and a track already
 * under way is never blocked, so putting fulfilment on hold later cannot strand
 * a parcel that is already travelling. A Store Order moves through its own named
 * workflow and is not governed here.
 *
 * One rule in one place: the manual status endpoints, shipment creation and the
 * screen's list of offered moves all ask this.
 */
class DispatchGate
{
    /**
     * Whether this order's fulfilment lets delivery and courier begin.
     */
    public function isOpen(Order $order): bool
    {
        return ! $this->appliesTo($order) || $order->fulfillment_status->isReadyForDispatch();
    }

    public function blocksDelivery(Order $order, OrderDeliveryStatus $to): bool
    {
        // A move the state machine does not allow is its refusal to make, not this one's.
        if (! in_array($to, $order->delivery_status->transitionsTo(), true)) {
            return false;
        }

        if ($this->isOpen($order) || in_array($to, [OrderDeliveryStatus::OnHold, OrderDeliveryStatus::Cancelled], true)) {
            return false;
        }

        return $order->delivery_status === OrderDeliveryStatus::NotShipped
            || $to === OrderDeliveryStatus::CourierAssigned;
    }

    public function blocksCourier(Order $order, OrderCourierStatus $to): bool
    {
        if (! in_array($to, $order->courier_status->transitionsTo(), true)) {
            return false;
        }

        if ($this->isOpen($order) || $to === OrderCourierStatus::Cancelled) {
            return false;
        }

        return $order->courier_status === OrderCourierStatus::Unassigned
            || $to === OrderCourierStatus::Assigned;
    }

    /**
     * Why it is closed, in words, for the person who tried.
     */
    public function refusal(Order $order): string
    {
        return __('orders.admin.refused.fulfilment_first', [
            'status' => $order->fulfillment_status->label(),
            'needed' => OrderFulfillmentStatus::ReadyForDispatch->label(),
        ]);
    }

    protected function appliesTo(Order $order): bool
    {
        return $order->source !== OrderSource::Website;
    }
}
