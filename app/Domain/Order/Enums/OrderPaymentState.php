<?php

namespace App\Domain\Order\Enums;

use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Payment;

/**
 * Where an order's payment stands, in the words its account needs (§10.2).
 *
 * Read from the payment every time — an order keeps no payment status of its
 * own — and folded into the handful of situations a buyer acts on differently:
 * still to pay, being confirmed, paid, did not go through, or money that arrived
 * after the order had already closed and is waiting on a person.
 */
enum OrderPaymentState: string
{
    case Awaiting = 'awaiting';
    case Confirming = 'confirming';
    case Paid = 'paid';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Reconciliation = 'reconciliation';
    case Refunded = 'refunded';

    public static function of(?Payment $payment): ?self
    {
        return match ($payment?->status) {
            null => null,
            PaymentStatus::Draft, PaymentStatus::Initiated => self::Awaiting,
            PaymentStatus::Pending => self::Confirming,
            PaymentStatus::Paid => self::Paid,
            PaymentStatus::Failed => self::Failed,
            PaymentStatus::Cancelled => self::Cancelled,
            PaymentStatus::ReconciliationRequired => self::Reconciliation,
            PaymentStatus::Refunded, PaymentStatus::PartiallyRefunded => self::Refunded,
        };
    }
}
