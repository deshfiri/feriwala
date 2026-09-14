<?php

namespace App\Domain\Wholesale\Queries;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\CouponValidator;
use App\Domain\Wholesale\Data\CheckoutQuote;
use App\Domain\Wholesale\Models\Cart;
use App\Support\Money\Money;

/**
 * Price a wholesale checkout on the server, every time (§14, §36.1).
 *
 * Built on {@see PriceCart} for the goods and on the one coupon engine,
 * {@see CouponValidator}, for the discount: the code a person entered is checked
 * again on every request against the subtotal just priced, so a code that has
 * since expired, run out or stopped qualifying simply stops taking anything off —
 * and says why.
 */
class PriceCheckout
{
    public function __construct(
        protected PriceCart $pricing,
        protected CouponValidator $coupons,
    ) {}

    public function quote(?Cart $cart, BusinessAccount $account): CheckoutQuote
    {
        $goods = $this->pricing->quote($cart, $account);
        $currency = $goods->subtotal->currency;

        $coupon = $cart?->coupon_code === null
            ? null
            : $this->coupons->validateForWholesale($cart->coupon_code, $account, $goods->subtotal);

        $discount = $coupon !== null && $coupon->isAccepted && $coupon->discount !== null
            ? $coupon->discount
            : Money::zero($currency);

        return new CheckoutQuote(
            cart: $goods,
            coupon: $coupon,
            discount: $discount,
            total: $goods->subtotal->minus($discount),
        );
    }
}
