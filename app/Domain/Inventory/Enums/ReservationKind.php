<?php

namespace App\Domain\Inventory\Enums;

/**
 * The two order types a reservation is held for (contract §6.1.2).
 *
 * Each has its own window: an online payment is expected within minutes, a
 * cash-on-delivery order waits for a confirmation call.
 */
enum ReservationKind: string
{
    /** Held until the payment is verified, failed, cancelled or runs out. */
    case OnlinePayment = 'online_payment';

    /** Held until the customer or an administrator confirms the order. */
    case CashOnDelivery = 'cod';

    public function label(): string
    {
        return match ($this) {
            self::OnlinePayment => 'Online payment',
            self::CashOnDelivery => 'Cash on delivery',
        };
    }
}
