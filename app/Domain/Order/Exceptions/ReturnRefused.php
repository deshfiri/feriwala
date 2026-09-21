<?php

namespace App\Domain\Order\Exceptions;

use RuntimeException;

/**
 * A return the ERP will not take, or a step on one it will not take now
 * (§18.2, contract §6.3, P6-12).
 *
 * Carries the HTTP status and a stable code, in the same shape as an order
 * refusal, so one answer serves the storefront, the partner's screen and the
 * staff screen. Details never name another partner's data, a warehouse, a cost
 * or an internal identifier (D12).
 */
class ReturnRefused extends RuntimeException
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

    /**
     * The order has not been delivered, so there is nothing to send back yet.
     */
    public static function notDelivered(): self
    {
        return new self(409, 'order_not_returnable', 'This order cannot be returned in its current state.');
    }

    public static function windowClosed(?string $closedAt = null): self
    {
        return new self(409, 'return_window_closed', 'The time to send this order back has passed.', array_filter([
            'window_closed_at' => $closedAt,
        ]));
    }

    public static function nothingReturnable(): self
    {
        return new self(422, 'nothing_returnable', 'Everything on this order has already been sent back.');
    }

    public static function lineNotReturnable(string $sku, int $requested, int $returnable): self
    {
        return new self(422, 'quantity_not_returnable', sprintf('Only %d of SKU %s can be sent back.', $returnable, $sku), [
            'sku' => $sku,
            'requested' => $requested,
            'returnable' => $returnable,
        ]);
    }

    public static function lineNotOnOrder(string $sku): self
    {
        return new self(422, 'line_not_on_order', 'That line is not part of this order.', ['sku' => $sku]);
    }

    public static function noLines(): self
    {
        return new self(422, 'no_lines', 'A return names at least one line and one unit.');
    }

    /**
     * The move being asked for is not one this return can make now.
     */
    public static function notInThatState(): self
    {
        return new self(409, 'return_not_in_that_state', 'This return is not at a stage where that can be done.');
    }

    public static function decisionNeedsAReason(): self
    {
        return new self(422, 'decision_needs_a_reason', 'A decision on a return is recorded with the reason for it.');
    }

    public static function moreThanApproved(string $sku, int $received, int $approved): self
    {
        return new self(422, 'more_than_approved', sprintf('Only %d of SKU %s was approved to come back.', $approved, $sku), [
            'sku' => $sku,
            'received' => $received,
            'approved' => $approved,
        ]);
    }

    /**
     * The units were never recorded as sold, so there is nothing to bring back.
     *
     * Delivery is what moves units into sold stock (§19.1, §20). Until
     * fulfilment records it, a return has nothing to restore and this says so
     * rather than inventing a source for the units.
     */
    public static function nothingSoldToRestore(string $sku): self
    {
        return new self(409, 'stock_not_recorded_as_sold', sprintf(
            'SKU %s was never recorded as sold, so it cannot be brought back into stock.',
            $sku,
        ), ['sku' => $sku]);
    }

    public static function refundAlreadyOpen(): self
    {
        return new self(409, 'refund_already_open', 'A refund for this order\'s goods is already waiting for a decision. Decide that one first.');
    }

    public static function notAwaitingManualRefund(): self
    {
        return new self(409, 'refund_not_manual', 'This refund is not one a person settles by hand.');
    }

    public static function busy(): self
    {
        return new self(409, 'return_busy', 'This return is being processed by another request. Retry shortly.');
    }
}
