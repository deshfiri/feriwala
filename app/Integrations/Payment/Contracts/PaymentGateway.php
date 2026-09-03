<?php

namespace App\Integrations\Payment\Contracts;

use App\Integrations\Payment\Data\GatewayRedirect;
use App\Integrations\Payment\Data\GatewayResult;
use App\Integrations\Payment\Data\PaymentIntent;
use Illuminate\Http\Request;

/**
 * One payment gateway (§26).
 *
 * EPS, SSLCommerz, SurjoPay, AmarPay, bKash, Nagad, Stripe, and PayPal all
 * implement this. Adding a gateway is one class plus a config entry — the core
 * payment flow never changes (D7).
 *
 * Three rules every implementation must hold to:
 *
 *   1. **Never trust the browser.** A user returning from a gateway can edit
 *      anything in that redirect. The callback tells you *which* payment to look
 *      at; {@see verify()} tells you what actually happened.
 *   2. **Verify server-to-server before releasing anything.** No account is
 *      activated and no wallet credited on a redirect alone.
 *   3. **Never throw for a declined payment.** A decline is a result. Only
 *      genuine faults — an unreachable gateway, a malformed response — throw.
 */
interface PaymentGateway
{
    /**
     * Begin a payment and get somewhere to send the user.
     */
    public function initiate(PaymentIntent $intent): GatewayRedirect;

    /**
     * Read the gateway's return request into a result.
     *
     * Establishes identity only. The outcome it reports is a claim until
     * {@see verify()} confirms it against the gateway itself.
     */
    public function handleCallback(Request $request): GatewayResult;

    /**
     * Whether a webhook or IPN request genuinely came from the gateway.
     *
     * Must compare in constant time and must fail closed on anything
     * unexpected — an unverified webhook is an anonymous request claiming money
     * arrived.
     */
    public function verifyWebhookSignature(Request $request): bool;

    /**
     * Ask the gateway directly what happened to a transaction.
     *
     * The authoritative answer. Everything that releases value depends on this,
     * not on a redirect or a webhook body.
     */
    public function verify(string $gatewayReference): GatewayResult;

    /**
     * The identifier used in config, logs, and the payments table.
     */
    public function name(): string;

    /**
     * Whether this gateway is running against its sandbox (§26.4).
     */
    public function isSandbox(): bool;
}
