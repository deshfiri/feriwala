<?php

namespace App\Domain\Wholesale\Exceptions;

use RuntimeException;

/**
 * A checkout step the rules do not allow, refused before anything is written
 * (§14).
 *
 * Each refusal says what to do about it, in the reader's language, and names the
 * field it belongs to.
 */
class CheckoutRefused extends RuntimeException
{
    public function __construct(string $message, public readonly string $field)
    {
        parent::__construct($message);
    }

    /**
     * A cart with a problem on a line, a price not yet accepted, or nothing in it
     * does not go on to checkout.
     */
    public static function cartNotReady(): self
    {
        return new self(__('wholesale.refused.cart_not_ready'), 'cart');
    }

    public static function addressesMissing(): self
    {
        return new self(__('wholesale.refused.addresses_missing'), 'addresses');
    }

    /**
     * Only a gateway that is switched on, configured and takes this currency is a
     * way to pay.
     */
    public static function paymentMethodUnavailable(): self
    {
        return new self(__('wholesale.refused.payment_method_unavailable'), 'payment_method');
    }

    /**
     * What the person agreed to is no longer what the server prices now.
     */
    public static function changed(): self
    {
        return new self(__('wholesale.refused.checkout_changed'), 'fingerprint');
    }
}
