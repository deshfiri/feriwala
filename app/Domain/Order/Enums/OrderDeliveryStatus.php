<?php

namespace App\Domain\Order\Enums;

use App\Domain\Courier\Models\Shipment;
use App\Support\StateMachine\TransitionableState;
use App\Support\Status\HasTranslatedLabel;

/**
 * Where an order stands in delivery (§18, §21, P6.C).
 *
 * Driven by the {@see Shipment} it travels on, one
 * level coarser than {@see OrderCourierStatus} — a courier handover, an
 * in-transit scan and an out-for-delivery scan are all still "in transit"
 * here, because this is the axis a buyer and the order's own status
 * ({@see OrderStatus}) care about, not the courier's internal detail.
 *
 * The `order_delivery_statuses`/`order_delivery_status_transitions` tables
 * are seeded from these cases and {@see transitionsTo()}.
 */
enum OrderDeliveryStatus: string implements TransitionableState
{
    use HasTranslatedLabel;

    case NotShipped = 'not_shipped';
    case CourierAssigned = 'courier_assigned';
    case Shipped = 'shipped';
    case InTransit = 'in_transit';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case FailedDelivery = 'failed_delivery';
    case ReturnRequested = 'return_requested';
    case Returned = 'returned';
    case Refunded = 'refunded';
    case OnHold = 'on_hold';
    case Cancelled = 'cancelled';

    /**
     * @return array<int, self>
     */
    public function transitionsTo(): array
    {
        return match ($this) {
            self::NotShipped => [self::CourierAssigned, self::OnHold, self::Cancelled],
            self::CourierAssigned => [self::Shipped, self::OnHold, self::Cancelled],

            // Once it has left the warehouse there is no hold and no cancel,
            // only where it ends up -- mirrors OrderStatus's own rule for the
            // same leg.
            self::Shipped => [self::InTransit, self::OutForDelivery, self::FailedDelivery],
            self::InTransit => [self::OutForDelivery, self::FailedDelivery],
            self::OutForDelivery => [self::Delivered, self::FailedDelivery],

            self::Delivered => [self::ReturnRequested],

            // A failed attempt can be retried through another courier
            // handover, sent back for a return, or stopped for review.
            self::FailedDelivery => [self::CourierAssigned, self::ReturnRequested, self::OnHold, self::Cancelled],

            self::ReturnRequested => [self::Returned, self::OnHold],
            self::Returned => [self::Refunded],
            self::Refunded => [],

            self::OnHold => [self::NotShipped, self::CourierAssigned, self::Shipped, self::InTransit, self::OutForDelivery, self::Cancelled],

            self::Cancelled => [],
        };
    }

    public function isTerminal(): bool
    {
        return $this->transitionsTo() === [];
    }

    protected static function statusLabelGroup(): string
    {
        return 'order_delivery';
    }

    public function tone(): string
    {
        return match ($this) {
            self::Delivered => 'success',
            self::OnHold, self::FailedDelivery, self::ReturnRequested => 'warning',
            self::Cancelled => 'danger',
            self::NotShipped, self::Returned, self::Refunded => 'neutral',
            default => 'info',
        };
    }
}
