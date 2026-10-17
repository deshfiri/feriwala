<?php

namespace App\Domain\Order\Enums;

/**
 * The business steps a Store Order is shown moving through, mapped onto the
 * order's own typed statuses (one source of truth: nothing here is stored).
 *
 *   Pending     New · Pending confirmation · Customer verification · Payment pending
 *   Confirmed   Confirmed · Paid
 *   Processing  Processing · Stock reserved · Ready for fulfilment · Picking
 *   Packing     Packing
 *   Packed      Ready for pickup
 *   Dispatched  Courier assigned · Shipped (and later: in transit, delivery failed)
 *   Delivered   Delivered
 *   Completed   Completed
 *
 * The courier steps after Dispatch (Courier booked, Picked up, In transit, Out
 * for delivery) come from the courier and delivery axes, not from here.
 */
enum StoreOrderStep: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Processing = 'processing';
    case Packing = 'packing';
    case Packed = 'packed';
    case Dispatched = 'dispatched';
    case Delivered = 'delivered';
    case Completed = 'completed';

    public static function for(OrderStatus $status): ?self
    {
        return match ($status) {
            OrderStatus::Draft, OrderStatus::New, OrderStatus::PendingConfirmation,
            OrderStatus::CustomerVerificationPending, OrderStatus::PaymentPending => self::Pending,
            OrderStatus::Confirmed, OrderStatus::Paid => self::Confirmed,
            OrderStatus::Processing, OrderStatus::StockReserved,
            OrderStatus::ReadyForFulfillment, OrderStatus::Picking => self::Processing,
            OrderStatus::Packing => self::Packing,
            OrderStatus::ReadyForPickup => self::Packed,
            OrderStatus::CourierAssigned, OrderStatus::Shipped,
            OrderStatus::InTransit, OrderStatus::DeliveryFailed => self::Dispatched,
            OrderStatus::Delivered => self::Delivered,
            OrderStatus::Completed => self::Completed,
            default => null,
        };
    }

    /**
     * Whether an order at `$status` has left the warehouse, so the courier and
     * delivery axes may move. Before that they are refused.
     */
    public static function hasDispatched(OrderStatus $status): bool
    {
        return in_array($status, [
            OrderStatus::Shipped, OrderStatus::InTransit, OrderStatus::DeliveryFailed, OrderStatus::Delivered,
            OrderStatus::Completed, OrderStatus::ReturnRequested, OrderStatus::ReturnApproved, OrderStatus::Returning,
            OrderStatus::Returned,
        ], true);
    }

    public function label(): string
    {
        return __('store_orders.steps.'.$this->value);
    }
}
