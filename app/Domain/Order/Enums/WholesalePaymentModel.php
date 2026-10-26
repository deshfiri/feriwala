<?php

namespace App\Domain\Order\Enums;

/**
 * How an ERP wholesale order was charged when it was placed (D34).
 *
 * Stamped once, on the order, and never changed. An order placed before it
 * existed has none: it was charged under the delivery-only rule for a
 * Non-Conditional account (or in full, for a Conditional one) and keeps being
 * handled by what it stored — never by what its account is today.
 */
enum WholesalePaymentModel: string
{
    /** The whole server-calculated total, through a gateway or the wallet. */
    case Full = 'full';
}
