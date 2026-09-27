<?php

namespace App\Domain\Order\Exceptions;

use RuntimeException;

/**
 * An order step the rules do not allow, refused before anything is kept (§14).
 *
 * Each refusal says what to do about it, in the reader's language, and names the
 * field it belongs to.
 */
class OrderRefused extends RuntimeException
{
    public function __construct(string $message, public readonly string $field)
    {
        parent::__construct($message);
    }

    /** Payment starts only from a checkout the person has confirmed. */
    public static function checkoutNotConfirmed(): self
    {
        return new self(__('orders.refused.checkout_not_confirmed'), 'confirmation');
    }

    /** What the person agreed to is no longer what the server prices now. */
    public static function checkoutChanged(): self
    {
        return new self(__('wholesale.refused.checkout_changed'), 'fingerprint');
    }

    /**
     * An outstanding KYC re-verification blocks new wholesale orders (§7.4).
     *
     * The message is the account holder's, never the reviewer's internal
     * reason for asking (§7.3): they are told verification is outstanding and
     * by when, not what was suspected.
     */
    public static function kycReverificationOutstanding(string $message): self
    {
        return new self($message, 'kyc');
    }

    public static function cartNotReady(): self
    {
        return new self(__('wholesale.refused.cart_not_ready'), 'cart');
    }

    public static function addressesMissing(): self
    {
        return new self(__('wholesale.refused.addresses_missing'), 'addresses');
    }

    public static function paymentMethodUnavailable(): self
    {
        return new self(__('wholesale.refused.payment_method_unavailable'), 'payment_method');
    }

    public static function nothingToPay(): self
    {
        return new self(__('orders.refused.nothing_to_pay'), 'cart');
    }

    /** The central stock for a line went between the checkout and the order. */
    public static function stockUnavailable(): self
    {
        return new self(__('orders.refused.stock_unavailable'), 'stock');
    }

    /** The coupon's last use went between the checkout and the order. */
    public static function couponUnavailable(string $message): self
    {
        return new self($message, 'coupon');
    }

    /** This cart already has an order waiting for its payment. */
    public static function awaitingPayment(): self
    {
        return new self(__('orders.refused.awaiting_payment'), 'order');
    }

    /** Only an order still waiting for its payment can be paid for or cancelled. */
    public static function notAwaitingPayment(): self
    {
        return new self(__('orders.refused.not_awaiting_payment'), 'order');
    }

    /** The time to pay for this order has run out. */
    public static function paymentWindowClosed(): self
    {
        return new self(__('orders.refused.payment_window_closed'), 'order');
    }

    /** The gateway is still confirming this payment; nothing may close it now. */
    public static function paymentInFlight(): self
    {
        return new self(__('orders.refused.payment_in_flight'), 'order');
    }

    /** Too many people are ordering the same stock at once; trying again works. */
    public static function busy(): self
    {
        return new self(__('orders.refused.busy'), 'stock');
    }
}
