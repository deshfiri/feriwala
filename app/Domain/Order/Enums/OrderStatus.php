<?php

namespace App\Domain\Order\Enums;

use App\Support\StateMachine\TransitionableState;

/**
 * The order lifecycle: every system status §18.2 names (P6-3, P6-5).
 *
 * **The one authority for order statuses and the moves between them.** The
 * `order_statuses` table is seeded from these cases and `order_status_transitions`
 * from {@see transitionsTo()}, so the database can hold an order to the same map
 * the application does; a test fails the moment the two disagree.
 *
 * The map is conservative: a move is here only where an order can genuinely go
 * next, whatever the source. Whether a particular move is allowed *for this order
 * now* — a settled payment cannot simply be cancelled, it goes to a refund — is
 * the job of the action making it, on top of this.
 *
 * Admin-defined custom statuses (§18.2, P6-4) are not built yet.
 */
enum OrderStatus: string implements TransitionableState
{
    case Draft = 'draft';
    case New = 'new';
    case PendingConfirmation = 'pending_confirmation';
    case CustomerVerificationPending = 'customer_verification_pending';
    case Confirmed = 'confirmed';
    case PaymentPending = 'payment_pending';
    case Paid = 'paid';
    case Processing = 'processing';
    case StockReserved = 'stock_reserved';
    case ReadyForFulfillment = 'ready_for_fulfillment';
    case Picking = 'picking';
    case Packing = 'packing';
    case ReadyForPickup = 'ready_for_pickup';
    case CourierAssigned = 'courier_assigned';
    case Shipped = 'shipped';
    case InTransit = 'in_transit';
    case Delivered = 'delivered';
    case Completed = 'completed';
    case DeliveryFailed = 'delivery_failed';

    /**
     * Stopped for a person to look at. For a wholesale order this is the state a
     * settled payment lands in when the stock it was reserving cannot be committed
     * (P4-10) — money arrived and the order cannot simply go ahead.
     */
    case OnHold = 'on_hold';

    case Cancelled = 'cancelled';
    case ReturnRequested = 'return_requested';
    case ReturnApproved = 'return_approved';
    case Returning = 'returning';
    case Returned = 'returned';
    case RefundPending = 'refund_pending';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';

    /**
     * @return array<int, self>
     */
    public function transitionsTo(): array
    {
        return match ($this) {
            self::Draft => [self::New, self::PendingConfirmation, self::PaymentPending, self::Cancelled],

            self::New => [
                self::PendingConfirmation, self::CustomerVerificationPending, self::Confirmed,
                self::PaymentPending, self::OnHold, self::Cancelled,
            ],

            self::PendingConfirmation => [self::CustomerVerificationPending, self::Confirmed, self::OnHold, self::Cancelled],
            self::CustomerVerificationPending => [self::Confirmed, self::OnHold, self::Cancelled],

            self::Confirmed => [
                self::PaymentPending, self::Processing, self::StockReserved,
                self::ReadyForFulfillment, self::OnHold, self::Cancelled,
            ],

            // Paid, or not: the payment settles, stops the order for review, or never arrives.
            self::PaymentPending => [self::Paid, self::OnHold, self::Cancelled],

            // Money has arrived, so the way out that is not fulfilment is a refund.
            self::Paid => [self::Processing, self::StockReserved, self::ReadyForFulfillment, self::OnHold, self::RefundPending],

            self::Processing => [self::StockReserved, self::ReadyForFulfillment, self::OnHold, self::RefundPending, self::Cancelled],
            self::StockReserved => [self::Processing, self::ReadyForFulfillment, self::OnHold, self::RefundPending, self::Cancelled],
            self::ReadyForFulfillment => [self::Picking, self::OnHold, self::RefundPending, self::Cancelled],

            self::Picking => [self::Packing, self::ReadyForFulfillment, self::OnHold],
            self::Packing => [self::ReadyForPickup, self::Picking, self::OnHold],
            self::ReadyForPickup => [self::CourierAssigned, self::OnHold],
            self::CourierAssigned => [self::Shipped, self::ReadyForPickup, self::OnHold],

            // Once it has left the warehouse there is no hold and no cancel, only where it ends up.
            self::Shipped => [self::InTransit, self::Delivered, self::DeliveryFailed],
            self::InTransit => [self::Delivered, self::DeliveryFailed],
            self::DeliveryFailed => [self::InTransit, self::Returning, self::OnHold, self::Cancelled],

            self::Delivered => [self::Completed, self::ReturnRequested],
            self::Completed => [self::ReturnRequested],

            // A hold resumes where the order can sensibly continue, or ends it.
            self::OnHold => [
                self::New, self::PendingConfirmation, self::Confirmed, self::PaymentPending,
                self::Paid, self::Processing, self::StockReserved, self::ReadyForFulfillment,
                self::RefundPending, self::Cancelled,
            ],

            self::ReturnRequested => [self::ReturnApproved, self::Completed, self::OnHold],
            self::ReturnApproved => [self::Returning, self::OnHold],
            self::Returning => [self::Returned],
            self::Returned => [self::RefundPending, self::Completed],

            self::RefundPending => [self::PartiallyRefunded, self::Refunded],
            self::PartiallyRefunded => [self::Refunded],

            self::Cancelled, self::Refunded => [],
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Cancelled, self::Refunded], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::New => 'New',
            self::PendingConfirmation => 'Pending confirmation',
            self::CustomerVerificationPending => 'Customer verification pending',
            self::Confirmed => 'Confirmed',
            self::PaymentPending => 'Payment pending',
            self::Paid => 'Paid',
            self::Processing => 'Processing',
            self::StockReserved => 'Stock reserved',
            self::ReadyForFulfillment => 'Ready for fulfillment',
            self::Picking => 'Picking',
            self::Packing => 'Packing',
            self::ReadyForPickup => 'Ready for pickup',
            self::CourierAssigned => 'Courier assigned',
            self::Shipped => 'Shipped',
            self::InTransit => 'In transit',
            self::Delivered => 'Delivered',
            self::Completed => 'Completed',
            self::DeliveryFailed => 'Delivery failed',
            self::OnHold => 'On hold',
            self::Cancelled => 'Cancelled',
            self::ReturnRequested => 'Return requested',
            self::ReturnApproved => 'Return approved',
            self::Returning => 'Returning',
            self::Returned => 'Returned',
            self::RefundPending => 'Refund pending',
            self::PartiallyRefunded => 'Partially refunded',
            self::Refunded => 'Refunded',
        };
    }

    /**
     * The tone a status is shown in. Always beside its label, never instead of it
     * (§33.9).
     */
    public function tone(): string
    {
        return match ($this) {
            self::Paid, self::Delivered, self::Completed => 'success',

            self::PaymentPending, self::PendingConfirmation, self::CustomerVerificationPending,
            self::OnHold, self::DeliveryFailed, self::ReturnRequested,
            self::RefundPending, self::PartiallyRefunded => 'warning',

            self::Cancelled => 'danger',

            self::Draft, self::Refunded => 'neutral',

            default => 'info',
        };
    }
}
