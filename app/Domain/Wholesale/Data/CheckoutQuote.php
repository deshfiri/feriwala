<?php

namespace App\Domain\Wholesale\Data;

use App\Domain\Account\Models\UserAddress;
use App\Domain\Billing\Data\CouponOutcome;
use App\Domain\Tax\Data\TaxBreakdown;
use App\Support\Money\Money;

/**
 * A wholesale checkout as the server prices it now (§14, P4-6, P4-7).
 *
 * The cart's own quote, the coupon somebody entered as it stands today — accepted
 * with what it takes off, or refused with why — the delivery charge, the tax on
 * goods and delivery, and the total that follows, with the addresses the order
 * would go to. Every figure is worked out again on each request; nothing here was
 * sent by the browser.
 */
readonly class CheckoutQuote
{
    public function __construct(
        public CartQuote $cart,
        public ?CouponOutcome $coupon,
        public Money $discount,
        public Money $delivery,
        public TaxBreakdown $tax,
        public Money $total,
        public ?UserAddress $billingAddress = null,
        public ?UserAddress $shippingAddress = null,
    ) {}

    public function hasAddresses(): bool
    {
        return $this->billingAddress !== null && $this->shippingAddress !== null;
    }

    /**
     * Whether this checkout can go on to confirmation: the cart passes its own
     * checks and the order has somewhere to be billed and delivered.
     */
    public function isReadyToConfirm(): bool
    {
        return $this->cart->isReadyForCheckout() && $this->hasAddresses();
    }
}
