<?php

namespace App\Domain\Billing\Events;

/**
 * A Partner-owned payment has been verified with the provider (D36).
 *
 * The explicit hand-off between the two halves of Model B. Verification proves
 * the customer paid the Partner; it does **not** make the order actionable.
 * Banij's receivable still has to be collected from the Partner's Wallet first,
 * and that is a separate, all-or-nothing step that owns the decision to release
 * or hold the order.
 *
 * Raised once, after the payment has been marked paid and the transaction has
 * committed. It carries identifiers only — never a credential, a provider
 * payload or anything about the customer — so it is safe to queue and to log.
 *
 * An order waiting for that step is exactly one whose Partner-owned payment is
 * paid while the order is still `payment_pending`; the sweep that repairs a
 * missed event finds them the same way.
 */
final readonly class PartnerGatewayPaymentVerified
{
    public function __construct(
        public int $paymentId,
        public ?int $orderId,
    ) {}
}
