<?php

namespace App\Integrations\Payment\Data;

use App\Support\Money\Money;

/**
 * A request to give money back through a provider (§26.3, §26.4).
 *
 * Built from stored payment facts, never from request input: the amount sent to
 * a provider is the amount Feriwala calculated is still refundable, not a figure
 * that arrived in a form (§36.1).
 *
 * `idempotencyKey` is the whole defence against refunding twice. Providers that
 * accept one honour it; for those that do not, the application's own unique
 * index does the same job before the request is ever sent.
 */
readonly class RefundIntent
{
    public function __construct(
        /** Our payment's reference — what the provider knows as the invoice. */
        public string $reference,
        /** The provider's own transaction identifier. */
        public string $gatewayReference,
        public Money $amount,
        /** What the original payment was for, in full. */
        public Money $originalAmount,
        public string $reason,
        public string $idempotencyKey,
    ) {}

    /**
     * Whether this returns the whole payment rather than part of it.
     *
     * Some providers have separate endpoints, or refuse partials outright, so
     * the difference has to be answerable before the call is made.
     */
    public function isFull(): bool
    {
        return $this->amount->equals($this->originalAmount);
    }
}
