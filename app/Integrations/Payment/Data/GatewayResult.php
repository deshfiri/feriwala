<?php

namespace App\Integrations\Payment\Data;

use App\Support\Money\Money;

/**
 * What a gateway says happened to a transaction.
 *
 * A decline is a result, not an exception — only genuine faults throw.
 *
 * `amount` is what the **gateway** reports, which is not necessarily what was
 * requested. Comparing the two is how a tampered redirect is caught, so the two
 * figures are deliberately kept apart rather than assumed equal.
 */
class GatewayResult
{
    private function __construct(
        public readonly GatewayOutcome $outcome,
        public readonly ?string $reference = null,
        public readonly ?string $gatewayReference = null,
        public readonly ?Money $amount = null,
        public readonly ?string $error = null,
        public readonly ?string $errorCode = null,
        /** @var array<string, mixed> */
        public readonly array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function paid(
        string $reference,
        string $gatewayReference,
        Money $amount,
        array $raw = [],
    ): self {
        return new self(
            outcome: GatewayOutcome::Paid,
            reference: $reference,
            gatewayReference: $gatewayReference,
            amount: $amount,
            raw: $raw,
        );
    }

    /**
     * Accepted but not yet settled — a bank transfer, or a gateway holding for
     * risk review. Must never release value.
     *
     * @param  array<string, mixed>  $raw
     */
    public static function pending(
        string $reference,
        ?string $gatewayReference = null,
        array $raw = [],
    ): self {
        return new self(
            outcome: GatewayOutcome::Pending,
            reference: $reference,
            gatewayReference: $gatewayReference,
            raw: $raw,
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function failed(
        ?string $reference,
        string $error,
        ?string $errorCode = null,
        array $raw = [],
    ): self {
        return new self(
            outcome: GatewayOutcome::Failed,
            reference: $reference,
            error: $error,
            errorCode: $errorCode,
            raw: $raw,
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function cancelled(?string $reference, array $raw = []): self
    {
        return new self(
            outcome: GatewayOutcome::Cancelled,
            reference: $reference,
            raw: $raw,
        );
    }

    public function isPaid(): bool
    {
        return $this->outcome === GatewayOutcome::Paid;
    }

    /**
     * Whether the gateway's amount matches what was expected.
     *
     * A mismatch means the request was tampered with or the gateway was
     * misconfigured. Either way it must not be treated as payment for this
     * order — the caller checks this before releasing anything.
     */
    public function matchesAmount(Money $expected): bool
    {
        return $this->amount !== null && $this->amount->equals($expected);
    }
}
