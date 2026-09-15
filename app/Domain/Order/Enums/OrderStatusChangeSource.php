<?php

namespace App\Domain\Order\Enums;

/**
 * What moved an order's status (§18.3).
 *
 * Recorded beside who made the change, because "nobody" is not an answer an
 * administrator can act on: a status the gateway moved and one the scheduler
 * moved are both made by no person, and they mean different things when an order
 * is being reconciled.
 */
enum OrderStatusChangeSource: string
{
    /** The person placing the order at checkout. */
    case Checkout = 'checkout';

    /** A payment the gateway confirmed or refused. */
    case PaymentGateway = 'payment_gateway';

    /** A scheduled pass — an unpaid order running out of time. */
    case Scheduler = 'scheduler';

    /** Somebody in the account that placed the order. */
    case Account = 'account';

    /** Platform staff. */
    case Staff = 'staff';

    /** The application itself, for anything else. */
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Checkout => 'Checkout',
            self::PaymentGateway => 'Payment gateway',
            self::Scheduler => 'Scheduled check',
            self::Account => 'Account',
            self::Staff => 'Staff',
            self::System => 'System',
        };
    }
}
