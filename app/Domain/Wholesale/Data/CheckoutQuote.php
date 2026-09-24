<?php

namespace App\Domain\Wholesale\Data;

use App\Domain\Account\Models\UserAddress;
use App\Domain\Billing\Data\CouponOutcome;
use App\Domain\Tax\Data\TaxBreakdown;
use App\Domain\Tax\Data\TaxCharge;
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
    /**
     * @param  array<int, CheckoutLineCharge>  $lineCharges  each purchasable line's discount share and tax, in cart order
     */
    public function __construct(
        public CartQuote $cart,
        public ?CouponOutcome $coupon,
        public Money $discount,
        public Money $delivery,
        public TaxBreakdown $tax,
        public Money $total,
        public ?UserAddress $billingAddress = null,
        public ?UserAddress $shippingAddress = null,
        public array $lineCharges = [],
        public ?TaxCharge $deliveryTax = null,
    ) {}

    /**
     * A digest of everything this checkout would charge and where it would go
     * (P4-8).
     *
     * Two quotes with the same fingerprint bill the same units at the same prices,
     * with the same discount, delivery charge and tax, to the same addresses. A
     * confirmation is held against it, so any later change — to the cart, a price,
     * a charge, a coupon or an address — shows as a confirmation that no longer
     * stands.
     */
    public function fingerprint(): string
    {
        $lines = [];

        foreach ($this->cart->lines as $line) {
            $lines[] = [
                'line' => $line->item->public_id,
                'product' => $line->item->product_id,
                'variant' => $line->item->product_variant_id,
                'quantity' => $line->item->quantity,
                'unit_price' => $line->unitPrice?->toDecimal(),
                'line_total' => $line->lineTotal?->toDecimal(),
                'problems' => $line->problems,
            ];
        }

        return hash('sha256', json_encode([
            'currency' => $this->total->currency->value,
            'lines' => $lines,
            'subtotal' => $this->cart->subtotal->toDecimal(),
            'coupon' => $this->coupon !== null && $this->coupon->isAccepted ? $this->coupon->coupon?->code : null,
            'discount' => $this->discount->toDecimal(),
            'delivery' => $this->delivery->toDecimal(),
            'tax' => array_map(fn (TaxCharge $charge) => [
                $charge->code,
                $charge->rateBasisPoints,
                $charge->mode->value,
                $charge->net->toDecimal(),
                $charge->tax->toDecimal(),
            ], $this->tax->charges),
            'total' => $this->total->toDecimal(),
            'billing' => $this->billingAddress?->toSnapshot(),
            'shipping' => $this->shippingAddress?->toSnapshot(),
        ], JSON_THROW_ON_ERROR));
    }

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
