<?php

namespace App\Domain\Order\Enums;

use App\Domain\Courier\Models\Shipment;
use App\Support\StateMachine\TransitionableState;
use App\Support\Status\HasTranslatedLabel;

/**
 * Where an order stands with a courier (§18, §21, P6.C).
 *
 * The finest-grained of the three fulfilment axes — driven directly by a
 * {@see Shipment}'s own status, which rolls up
 * into {@see OrderDeliveryStatus} on the order. An order with no shipment yet
 * is always `Unassigned`; every other case exists only once one has been
 * created.
 *
 * The `order_courier_statuses`/`order_courier_status_transitions` tables are
 * seeded from these cases and {@see transitionsTo()}.
 */
enum OrderCourierStatus: string implements TransitionableState
{
    use HasTranslatedLabel;

    case Unassigned = 'unassigned';
    case Assigned = 'assigned';
    case PickupRequested = 'pickup_requested';
    case PickedUp = 'picked_up';
    case InTransit = 'in_transit';
    case Delivered = 'delivered';
    case FailedDelivery = 'failed_delivery';
    case ReturnedToOrigin = 'returned_to_origin';
    case Cancelled = 'cancelled';

    /**
     * @return array<int, self>
     */
    public function transitionsTo(): array
    {
        return match ($this) {
            self::Unassigned => [self::Assigned, self::Cancelled],
            self::Assigned => [self::PickupRequested, self::Cancelled],
            self::PickupRequested => [self::PickedUp, self::Cancelled],
            self::PickedUp => [self::InTransit, self::FailedDelivery],
            self::InTransit => [self::Delivered, self::FailedDelivery],
            self::Delivered => [],

            // Retried through the same courier (reassigned) or sent back.
            self::FailedDelivery => [self::ReturnedToOrigin, self::Assigned],

            self::ReturnedToOrigin => [],
            self::Cancelled => [],
        };
    }

    public function isTerminal(): bool
    {
        return $this->transitionsTo() === [];
    }

    protected static function statusLabelGroup(): string
    {
        return 'order_courier';
    }

    public function tone(): string
    {
        return match ($this) {
            self::Delivered => 'success',
            self::FailedDelivery => 'warning',
            self::Cancelled => 'danger',
            self::Unassigned, self::ReturnedToOrigin => 'neutral',
            default => 'info',
        };
    }
}
