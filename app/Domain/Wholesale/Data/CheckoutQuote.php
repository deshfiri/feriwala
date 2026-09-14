<?php

namespace App\Domain\Wholesale\Data;

use App\Domain\Billing\Data\CouponOutcome;
use App\Support\Money\Money;

/**
 * A wholesale checkout as the server prices it now (§14, P4-6).
 *
 * The cart's own quote, the coupon somebody entered as it stands today — accepted
 * with what it takes off, or refused with why — and the total that follows. Every
 * figure is worked out again on each request; nothing here was sent by the
 * browser.
 */
readonly class CheckoutQuote
{
    public function __construct(
        public CartQuote $cart,
        public ?CouponOutcome $coupon,
        public Money $discount,
        public Money $total,
    ) {}
}
