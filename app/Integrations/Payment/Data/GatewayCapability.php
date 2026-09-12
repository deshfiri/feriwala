<?php

namespace App\Integrations\Payment\Data;

/**
 * What one provider can actually do (§26.4).
 *
 * The eight providers §26 names do not offer the same operations. Some verify a
 * transaction server-to-server and some only sign a callback; some refund in
 * part, some refund in full or not at all; some let you ask the status of a
 * refund and some tell you once and never again.
 *
 * Declaring this explicitly is what stops a screen offering a button that calls
 * an operation the provider has no endpoint for. A missing capability is not a
 * bug to work around — it is a fact about somebody else's system, and the honest
 * response is to not offer the action.
 *
 * A driver declares only what has been implemented **against that provider's own
 * documentation**. Anything unverified is absent, and absent means the
 * application refuses rather than guesses.
 */
enum GatewayCapability: string
{
    /** Start a payment and get somewhere to send the payer. */
    case Initiate = 'initiate';

    /**
     * Ask the provider directly what happened to a transaction.
     *
     * The one capability nothing may release value without. A provider that
     * cannot answer this cannot be used to take money here, whatever else it
     * offers.
     */
    case Verify = 'verify';

    /** Prove a webhook or IPN genuinely came from the provider. */
    case WebhookSignature = 'webhook_signature';

    /** Query a transaction's current status outside a callback. */
    case StatusQuery = 'status_query';

    /** Return the whole amount. */
    case RefundFull = 'refund_full';

    /** Return part of the amount, more than once if the provider allows it. */
    case RefundPartial = 'refund_partial';

    /** Ask what became of a refund that was accepted for processing. */
    case RefundStatus = 'refund_status';

    /**
     * Whether this capability concerns giving money back.
     */
    public function isRefund(): bool
    {
        return in_array($this, [self::RefundFull, self::RefundPartial, self::RefundStatus], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Initiate => 'Take a payment',
            self::Verify => 'Verify with the provider',
            self::WebhookSignature => 'Signed webhooks',
            self::StatusQuery => 'Status lookup',
            self::RefundFull => 'Full refund',
            self::RefundPartial => 'Partial refund',
            self::RefundStatus => 'Refund status',
        };
    }
}
