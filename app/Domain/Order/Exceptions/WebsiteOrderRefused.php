<?php

namespace App\Domain\Order\Exceptions;

use App\Domain\Order\Models\Order;
use App\Support\Money\Money;
use RuntimeException;

/**
 * A storefront's order the ERP will not take, in the contract's terms
 * (contract §4.5, §6.1, P5-23).
 *
 * Each refusal carries the HTTP status and stable `code` the contract names, a
 * message for a person, and details a storefront can act on. Details never hold
 * another partner's data, an internal identifier, a cost or a wholesale price —
 * only selling-side figures and what the storefront itself sent (D12).
 */
class WebsiteOrderRefused extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public static function notAcceptingOrders(): self
    {
        return new self(409, 'website_not_accepting_orders', 'This website is not taking orders in its current state.');
    }

    public static function productUnavailable(string $sku): self
    {
        return new self(422, 'product_unavailable', 'That product is not on sale on this website.', ['sku' => $sku]);
    }

    public static function quantityNotAllowed(string $sku, int $quantity, int $minimum, ?int $maximum): self
    {
        return new self(422, 'quantity_not_allowed', 'That quantity cannot be ordered for this product.', [
            'sku' => $sku,
            'requested' => $quantity,
            'minimum' => $minimum,
            'maximum' => $maximum,
        ]);
    }

    public static function insufficientStock(string $sku, int $requested, int $available): self
    {
        return new self(422, 'insufficient_stock', sprintf('Only %d units of SKU %s remain.', $available, $sku), [
            'sku' => $sku,
            'requested' => $requested,
            'available' => $available,
        ]);
    }

    /**
     * @param  array<string, mixed>  $authoritative
     */
    public static function priceMismatch(array $authoritative, ?Money $submittedGrandTotal): self
    {
        return new self(422, 'price_mismatch', 'Prices have changed since this cart was built. Ask the customer to review the updated total.', [
            'authoritative' => $authoritative,
            'submitted_grand_total' => $submittedGrandTotal?->jsonSerialize(),
        ]);
    }

    public static function paymentUnverified(): self
    {
        return new self(422, 'payment_unverified', 'A payment cannot be claimed by the storefront. Feriwala opens the payment and confirms it with the gateway.');
    }

    public static function paymentMethodUnavailable(string $method): self
    {
        return new self(422, 'payment_method_unavailable', 'That payment method is not available for this website.', ['method' => $method]);
    }

    public static function codNotAvailable(): self
    {
        return new self(422, 'payment_method_unavailable', 'This website cannot take cash on delivery.', ['method' => 'cod']);
    }

    public static function codLimitExceeded(Money $maximum): self
    {
        return new self(422, 'cod_limit_exceeded', 'This order is above what may be taken on delivery.', [
            'maximum' => $maximum->jsonSerialize(),
        ]);
    }

    public static function notAwaitingConfirmation(): self
    {
        return new self(409, 'order_not_awaiting_confirmation', 'This order is not waiting to be confirmed.');
    }

    /**
     * The code was wrong, has run out of guesses, or was never issued.
     *
     * One answer for all three: which of them it was tells somebody guessing
     * whether the order exists and how close they are (§6.2).
     */
    public static function confirmationRefused(): self
    {
        return new self(422, 'confirmation_refused', 'That code is not right, or it has expired. Ask for a new one.');
    }

    public static function confirmationExpired(): self
    {
        return new self(409, 'confirmation_expired', 'The time to confirm this order has passed.');
    }

    public static function confirmationTooSoon(int $seconds): self
    {
        return new self(429, 'confirmation_code_too_soon', 'A code was sent a moment ago. Wait before asking for another.', [
            'retry_after' => $seconds,
        ]);
    }

    public static function couponNotApplicable(string $code): self
    {
        return new self(422, 'coupon_not_applicable', 'Coupons are not available on this website.', ['coupon_code' => $code]);
    }

    public static function invalidMobileNumber(): self
    {
        return new self(422, 'invalid_mobile_number', 'The customer\'s mobile number could not be resolved.');
    }

    public static function returnUrlNotAllowed(): self
    {
        return new self(422, 'return_url_not_allowed', 'The return address must be an https address on this website.');
    }

    public static function duplicate(Order $order): self
    {
        return new self(409, 'duplicate_storefront_order', 'An order with this storefront reference already exists.', [
            'order' => ['id' => $order->public_id, 'reference' => $order->reference],
        ]);
    }

    public static function keyReused(): self
    {
        return new self(409, 'idempotency_key_reused', 'This idempotency key was already used for a different order.');
    }

    public static function busy(): self
    {
        return new self(409, 'request_in_progress', 'This order is being processed by another request. Retry shortly.');
    }
}
