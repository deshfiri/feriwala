<?php

namespace App\Integrations\Payment\Data;

use App\Domain\Billing\Actions\PreparePaymentAttempt;
use App\Domain\Billing\Models\Payment;
use App\Support\Money\Money;

/**
 * Everything a gateway needs to start a payment.
 *
 * Built from a stored {@see Payment} rather than from request input, so the
 * amount sent to the gateway is always the amount Feriwala calculated (§36.1).
 */
class PaymentIntent
{
    public function __construct(
        public readonly string $reference,
        public readonly Money $amount,
        public readonly string $customerName,
        public readonly string $customerEmail,
        public readonly ?string $customerMobile,
        public readonly string $successUrl,
        public readonly string $failUrl,
        public readonly string $cancelUrl,
        public readonly string $ipnUrl,
        public readonly string $description,
        public readonly ?PayerAddress $payerAddress = null,

        /**
         * The identifier this attempt is known by at a provider that wants one
         * of its own per attempt (EPS's merchantTransactionId). Already saved on
         * the payment when the gateway is called.
         */
        public readonly ?string $attemptReference = null,
        public readonly ?string $ipAddress = null,

        /** @var list<string> what the payer's best address still lacks, when none was complete */
        public readonly array $payerAddressMissing = [],
    ) {}

    public static function forPayment(
        Payment $payment,
        string $successUrl,
        string $failUrl,
        string $cancelUrl,
        string $ipnUrl,
    ): self {
        $user = $payment->businessAccount?->owner;

        $attempt = app(PreparePaymentAttempt::class)->handle($payment);

        return new self(
            reference: $payment->reference,
            amount: $payment->amount,
            customerName: (string) $user?->name,
            customerEmail: (string) $user?->email,
            customerMobile: $user?->mobile,
            successUrl: $successUrl,
            failUrl: $failUrl,
            cancelUrl: $cancelUrl,
            ipnUrl: $ipnUrl,
            description: $payment->purpose->label(),
            payerAddress: $attempt['address'],
            attemptReference: $attempt['reference'],
            // Laravel's own resolution, which honours the trusted-proxy
            // configuration and never reads a raw client-supplied header.
            ipAddress: request()->ip(),
            payerAddressMissing: $attempt['missing'],
        );
    }
}
