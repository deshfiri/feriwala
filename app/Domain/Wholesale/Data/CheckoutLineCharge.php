<?php

namespace App\Domain\Wholesale\Data;

use App\Domain\Tax\Data\TaxCharge;
use App\Support\Money\Money;

/**
 * What one cart line is charged at checkout, beyond its price (§14, P4-9).
 *
 * Its share of the discount and the tax on what it sells for after that share —
 * the figures an order line keeps as its own snapshot.
 */
readonly class CheckoutLineCharge
{
    public function __construct(
        public CartLineQuote $line,
        public Money $discount,
        public TaxCharge $tax,
    ) {}
}
