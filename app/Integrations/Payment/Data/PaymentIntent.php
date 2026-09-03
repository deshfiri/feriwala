<?php

namespace App\Integrations\Payment\Data;

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
    ) {}

    public static function forPayment(
        Payment $payment,
        string $successUrl,
        string $failUrl,
        string $cancelUrl,
        string $ipnUrl,
    ): self {
        $user = $payment->businessAccount?->owner;

        return new self(
            reference: $payment->reference,
            amount: $payment->amount_minor,
            customerName: (string) $user?->name,
            customerEmail: (string) $user?->email,
            customerMobile: $user?->mobile,
            successUrl: $successUrl,
            failUrl: $failUrl,
            cancelUrl: $cancelUrl,
            ipnUrl: $ipnUrl,
            description: $payment->purpose->label(),
        );
    }
}
