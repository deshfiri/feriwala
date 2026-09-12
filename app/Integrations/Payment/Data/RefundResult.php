<?php

namespace App\Integrations\Payment\Data;

use App\Support\Money\Money;

/**
 * What a provider says about a refund (§26.3, §26.4).
 *
 * Three outcomes, and the middle one is the one that matters. A refund is often
 * **accepted for processing** rather than completed: the provider has taken the
 * instruction and will settle it later, and treating that as done would reverse
 * money in our ledger that has not yet left theirs.
 *
 * So a refund is only ever treated as completed on {@see $outcome} being
 * `Succeeded`, confirmed by the provider — never on an HTTP 200 alone.
 *
 * A refusal is a result, not an exception. Only genuine faults throw.
 */
readonly class RefundResult
{
    private function __construct(
        public GatewayRefundOutcome $outcome,
        public ?string $gatewayRefundReference = null,
        public ?Money $amount = null,
        public ?string $error = null,
        public ?string $errorCode = null,
        /** @var array<string, mixed> */
        public array $raw = [],
    ) {}

    /**
     * The provider has returned the money.
     *
     * `amount` is null when the provider does not report one back — several
     * confirm a refund without restating its value. That is not a problem to
     * paper over with the requested figure: the caller already holds what it
     * asked for, and a figure invented here would look like the provider's own
     * confirmation of it.
     *
     * @param  array<string, mixed>  $raw
     */
    public static function succeeded(
        string $gatewayRefundReference,
        ?Money $amount = null,
        array $raw = [],
    ): self {
        return new self(
            outcome: GatewayRefundOutcome::Succeeded,
            gatewayRefundReference: $gatewayRefundReference,
            amount: $amount,
            raw: $raw,
        );
    }

    /**
     * Accepted, not yet settled. Must not be treated as money returned.
     *
     * @param  array<string, mixed>  $raw
     */
    public static function pending(
        ?string $gatewayRefundReference = null,
        ?Money $amount = null,
        array $raw = [],
    ): self {
        return new self(
            outcome: GatewayRefundOutcome::Pending,
            gatewayRefundReference: $gatewayRefundReference,
            amount: $amount,
            raw: $raw,
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function failed(
        string $error,
        ?string $errorCode = null,
        array $raw = [],
        ?string $gatewayRefundReference = null,
    ): self {
        return new self(
            outcome: GatewayRefundOutcome::Failed,
            gatewayRefundReference: $gatewayRefundReference,
            error: $error,
            errorCode: $errorCode,
            raw: $raw,
        );
    }

    public function isSucceeded(): bool
    {
        return $this->outcome === GatewayRefundOutcome::Succeeded;
    }

    public function isPending(): bool
    {
        return $this->outcome === GatewayRefundOutcome::Pending;
    }
}
