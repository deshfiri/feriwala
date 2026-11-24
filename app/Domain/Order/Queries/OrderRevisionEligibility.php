<?php

namespace App\Domain\Order\Queries;

use App\Domain\Courier\Enums\CourierBookingStatus;
use App\Domain\Courier\Models\CourierBookingAttempt;
use App\Domain\Courier\Models\Shipment;
use App\Domain\Order\Enums\OrderCourierStatus;
use App\Domain\Order\Enums\OrderDeliveryStatus;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;

/**
 * Whether an order's details may be revised right now, and if not, why (D38
 * "State rules").
 *
 * Before dispatch — no courier booked or assigned, nothing shipped, the order
 * still open — a revision is allowed. After that, what the courier was given
 * cannot be rewritten from here: a live Steadfast booking cannot be cancelled
 * or updated through its API, so staff are told exactly how to release it
 * first; a parcel already picked up or delivered is corrected through recall,
 * return or a replacement, never by editing the order.
 */
final class OrderRevisionEligibility
{
    /** The order statuses in which nothing has left the warehouse and the order is still open. */
    private const OPEN_BEFORE_DISPATCH = [
        OrderStatus::Draft, OrderStatus::New, OrderStatus::PendingConfirmation,
        OrderStatus::CustomerVerificationPending, OrderStatus::Confirmed, OrderStatus::PaymentPending,
        OrderStatus::Paid, OrderStatus::Processing, OrderStatus::StockReserved,
        OrderStatus::ReadyForFulfillment, OrderStatus::Picking, OrderStatus::Packing,
        OrderStatus::ReadyForPickup, OrderStatus::OnHold,
    ];

    /** Statuses a closed order ends in: corrections there are refunds, returns or a replacement. */
    private const DELIVERED = [OrderStatus::Delivered, OrderStatus::Completed];

    /**
     * The refusal code, or null when the order may be revised.
     *
     * Codes: `closed` (cancelled, expired, returned or refunded), `delivered`,
     * `in_transit` (picked up or on its way), `courier_booked` (a booking or
     * an assigned courier holds the old details).
     */
    public function blocker(Order $order): ?string
    {
        if (in_array($order->status, self::DELIVERED, true) || $order->delivery_status === OrderDeliveryStatus::Delivered) {
            return 'delivered';
        }

        if (in_array($order->courier_status, [OrderCourierStatus::PickedUp, OrderCourierStatus::InTransit], true)
            || in_array($order->delivery_status, [OrderDeliveryStatus::Shipped, OrderDeliveryStatus::InTransit, OrderDeliveryStatus::OutForDelivery], true)) {
            return 'in_transit';
        }

        if (! in_array($order->status, self::OPEN_BEFORE_DISPATCH, true)) {
            return $order->status === OrderStatus::CourierAssigned ? 'courier_booked' : 'closed';
        }

        if ($order->courier_status !== OrderCourierStatus::Unassigned
            || $order->delivery_status !== OrderDeliveryStatus::NotShipped
            || $this->hasLiveCourierBooking($order)) {
            return 'courier_booked';
        }

        return null;
    }

    public function allows(Order $order): bool
    {
        return $this->blocker($order) === null;
    }

    /**
     * A booking attempt the courier may hold (booked, pending or uncertain), or
     * a shipment that has not been cancelled.
     */
    private function hasLiveCourierBooking(Order $order): bool
    {
        $liveAttempt = CourierBookingAttempt::query()
            ->where('order_id', $order->id)
            ->whereIn('status', array_map(
                fn (CourierBookingStatus $status) => $status->value,
                array_filter(CourierBookingStatus::cases(), fn (CourierBookingStatus $status) => $status->isLive()),
            ))
            ->exists();

        return $liveAttempt || Shipment::query()
            ->where('order_id', $order->id)
            ->where('status', '<>', OrderCourierStatus::Cancelled->value)
            ->exists();
    }
}
