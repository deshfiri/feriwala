<?php

namespace App\Domain\Billing\Queries;

use App\Domain\Billing\Models\Payment;
use App\Integrations\Payment\Data\GatewayResult;

/**
 * Which payment a gateway notification is about (§26.4).
 *
 * Every provider names a transaction differently — `tran_id`, `mer_txnid`,
 * `paymentID`, `session_id`, `token` — so nothing here reads a request field.
 * The driver has already turned its own vocabulary into a {@see GatewayResult},
 * and this works only from that. Adding a gateway does not change this class.
 *
 * Two identifiers, and each is tried on its own terms:
 *
 *   1. **Our reference**, when the provider echoes it back. Exact, and the one
 *      most providers give.
 *   2. **The provider's transaction**, when it does not. bKash's callback
 *      carries nothing but a payment id; Stripe's carries a session id.
 *
 * **The gateway is part of the question.** A reference is only a reference for
 * the provider that was asked to take the money. Without that condition, a
 * notification from one provider could name a payment that belongs to another —
 * and a provider that anybody can sign for would be able to settle payments
 * routed elsewhere.
 */
class ResolveNotifiedPayment
{
    public function handle(string $gateway, GatewayResult $result): ?Payment
    {
        $payment = $this->byReference($gateway, $result->reference)
            ?? $this->byGatewayReference($gateway, $result->gatewayReference);

        if ($payment === null) {
            return null;
        }

        /*
         * Belt and braces: the lookups are already scoped to the gateway, but
         * this is the invariant the rest of the settlement path assumes, and it
         * is cheap to assert where it is easy to read.
         */
        return $payment->gateway === $gateway ? $payment : null;
    }

    protected function byReference(string $gateway, ?string $reference): ?Payment
    {
        if ($reference === null || $reference === '') {
            return null;
        }

        return Payment::query()
            ->where('gateway', $gateway)
            ->where('reference', $reference)
            ->first();
    }

    /**
     * Find a payment by the provider's own identifier.
     *
     * Matches either the transaction we were handed at initiation or the one the
     * provider settled under, because different providers hand back different
     * halves of the same payment.
     */
    protected function byGatewayReference(string $gateway, ?string $gatewayReference): ?Payment
    {
        if ($gatewayReference === null || $gatewayReference === '') {
            return null;
        }

        return Payment::query()
            ->where('gateway', $gateway)
            ->where(fn ($query) => $query
                ->where('gateway_reference', $gatewayReference)
                ->orWhere('gateway_settlement_reference', $gatewayReference))
            ->first();
    }
}
