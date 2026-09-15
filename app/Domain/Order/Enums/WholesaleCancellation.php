<?php

namespace App\Domain\Order\Enums;

use App\Domain\Billing\Enums\PaymentStatus;

/**
 * Why an unpaid ERP wholesale order was cancelled (§14, §19.1, P4-10).
 *
 * Each reason closes the order's payment the same way, gives back the stock held
 * for it, and says so on the order's timeline in words written for the buyer.
 */
enum WholesaleCancellation: string
{
    /** The gateway said the payment did not go through. */
    case PaymentFailed = 'payment_failed';

    /** The person backed out at the gateway. */
    case PaymentCancelled = 'payment_cancelled';

    /** The time to pay ran out. */
    case PaymentExpired = 'payment_expired';

    /** Somebody at the account cancelled it before paying. */
    case ByAccount = 'by_account';

    /** Platform staff cancelled it before it was paid. */
    case ByStaff = 'by_staff';

    /**
     * The status an open payment is closed with.
     */
    public function paymentStatus(): PaymentStatus
    {
        return $this === self::PaymentFailed ? PaymentStatus::Failed : PaymentStatus::Cancelled;
    }

    /**
     * Whether this is somebody's decision rather than the gateway's or the clock's.
     *
     * A person may not cancel an order whose payment the gateway is still
     * confirming: money on its way would arrive at an order that no longer wants it.
     */
    public function isByPerson(): bool
    {
        return $this === self::ByAccount || $this === self::ByStaff;
    }

    public function source(): OrderStatusChangeSource
    {
        return match ($this) {
            self::PaymentFailed, self::PaymentCancelled => OrderStatusChangeSource::PaymentGateway,
            self::PaymentExpired => OrderStatusChangeSource::Scheduler,
            self::ByAccount => OrderStatusChangeSource::Account,
            self::ByStaff => OrderStatusChangeSource::Staff,
        };
    }

    /** The reason kept on the order, for staff. */
    public function reason(): string
    {
        return match ($this) {
            self::PaymentFailed => 'The payment did not go through.',
            self::PaymentCancelled => 'The payment was cancelled at the gateway.',
            self::PaymentExpired => 'The time to pay ran out.',
            self::ByAccount => 'Cancelled by the account before payment.',
            self::ByStaff => 'Cancelled by staff before payment.',
        };
    }

    /** The translation key of the note the buyer reads on the timeline. */
    public function publicNote(): string
    {
        return 'orders.notes.cancelled_'.$this->value;
    }
}
