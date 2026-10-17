<?php

namespace App\Domain\Order\Enums;

/**
 * The only moves staff can make on a Store Order — one named action per step.
 *
 * There is no "set status" here. Each action is legal from exactly the order
 * statuses listed, and takes the order to exactly one status; asked from any
 * other, it is refused. That is what makes the sequence strict: Pending →
 * Confirmed → Processing → Packing → Packed → Dispatched, and nothing skips or
 * goes back.
 */
enum StoreOrderAction: string
{
    case Confirm = 'confirm';
    case StartProcessing = 'start_processing';
    case StartPacking = 'start_packing';
    case MarkPacked = 'mark_packed';
    case Dispatch = 'dispatch';

    /**
     * The order statuses this action may be taken from.
     *
     * Online orders that are still waiting for the gateway are not here:
     * confirmation of an online order is the verified payment, never a click.
     *
     * @return list<OrderStatus>
     */
    public function allowedFrom(): array
    {
        return match ($this) {
            self::Confirm => [OrderStatus::New, OrderStatus::PendingConfirmation, OrderStatus::CustomerVerificationPending],
            self::StartProcessing => [OrderStatus::Confirmed, OrderStatus::Paid],
            self::StartPacking => [OrderStatus::Processing, OrderStatus::StockReserved, OrderStatus::ReadyForFulfillment, OrderStatus::Picking],
            self::MarkPacked => [OrderStatus::Packing],
            self::Dispatch => [OrderStatus::ReadyForPickup, OrderStatus::CourierAssigned],
        };
    }

    public function to(): OrderStatus
    {
        return match ($this) {
            self::Confirm => OrderStatus::Confirmed,
            self::StartProcessing => OrderStatus::Processing,
            self::StartPacking => OrderStatus::Packing,
            self::MarkPacked => OrderStatus::ReadyForPickup,
            self::Dispatch => OrderStatus::Shipped,
        };
    }

    /**
     * The action that follows an order standing at `$status`, if any.
     */
    public static function nextFor(OrderStatus $status): ?self
    {
        foreach (self::cases() as $action) {
            if (in_array($status, $action->allowedFrom(), true)) {
                return $action;
            }
        }

        return null;
    }

    public function label(): string
    {
        return __('store_orders.actions.'.$this->value);
    }
}
