<?php

namespace App\Domain\Order\Enums;

/**
 * One kind of money in a Store Order's platform receivable (D36).
 *
 * Product tax is its own component rather than folded into product recovery, so a
 * reversal can return the tax in proportion to the goods; delivery and the tax
 * charged on it are kept apart for the same reason.
 */
enum ReceivableComponent: string
{
    /** What Feriwala keeps from an order line: the suggested price, or the product cost where that was all that was frozen. */
    case ProductRecovery = 'product_recovery';

    /** The tax on that line, whether it was added to the price or already inside it. */
    case ProductTax = 'product_tax';

    /** The delivery charge the customer was quoted. */
    case DeliveryCharge = 'delivery_charge';

    /** The tax charged on that delivery charge, when it was added on top of it. */
    case DeliveryTax = 'delivery_tax';
}
