<?php

namespace App\Domain\Wholesale\Actions;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\CouponValidator;
use App\Domain\Billing\Data\CouponOutcome;
use App\Domain\Wholesale\Exceptions\CheckoutRefused;
use App\Domain\Wholesale\Models\Cart;
use App\Domain\Wholesale\Queries\PriceCart;
use App\Models\User;

/**
 * Enter a coupon code at wholesale checkout (§14, P4-6).
 *
 * Checked through the one coupon engine against the goods subtotal the server has
 * just priced, and kept on the cart only when it applies — as the coupon's own
 * code, not whatever capitals were typed. Only the code is kept: what it takes off
 * is worked out again on every checkout render, and nothing is reserved or
 * redeemed here. Spending a coupon belongs to placing the order.
 */
class ApplyCheckoutCoupon
{
    public function __construct(
        protected OpenCart $carts,
        protected PriceCart $pricing,
        protected CouponValidator $coupons,
    ) {}

    /**
     * @throws CheckoutRefused when the cart is not ready for checkout
     */
    public function handle(User $user, BusinessAccount $account, string $code): CouponOutcome
    {
        $cart = $this->carts->find($user, $account);

        if ($cart === null) {
            throw CheckoutRefused::cartNotReady();
        }

        $quote = $this->pricing->quote($cart, $account);

        if (! $quote->isReadyForCheckout()) {
            throw CheckoutRefused::cartNotReady();
        }

        $outcome = $this->coupons->validateForWholesale($code, $account, $quote->subtotal);

        if ($outcome->isAccepted && $outcome->coupon !== null) {
            Cart::query()->whereKey($cart->id)->update(['coupon_code' => $outcome->coupon->code]);
        }

        return $outcome;
    }
}
