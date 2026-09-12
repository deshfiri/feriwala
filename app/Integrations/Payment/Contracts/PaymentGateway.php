<?php

namespace App\Integrations\Payment\Contracts;

use App\Integrations\Payment\Data\GatewayCapability;
use App\Integrations\Payment\Data\GatewayRedirect;
use App\Integrations\Payment\Data\GatewayResult;
use App\Integrations\Payment\Data\PaymentIntent;
use App\Integrations\Payment\Data\RefundIntent;
use App\Integrations\Payment\Data\RefundResult;
use App\Support\Money\Currency;
use Illuminate\Http\Request;

/**
 * One payment gateway (§26).
 *
 * EPS, SSLCommerz, SurjoPay, AmarPay, bKash, Nagad, Stripe, and PayPal all
 * implement this. Adding a gateway is one class plus a config entry — the core
 * payment flow never changes (D7).
 *
 * Four rules every implementation must hold to:
 *
 *   1. **Never trust the browser.** A user returning from a gateway can edit
 *      anything in that redirect. The callback tells you *which* payment to look
 *      at; {@see verify()} tells you what actually happened.
 *   2. **Verify server-to-server before releasing anything.** No account is
 *      activated and no wallet credited on a redirect alone.
 *   3. **Never throw for a declined payment.** A decline is a result. Only
 *      genuine faults — an unreachable gateway, a malformed response — throw.
 *   4. **Declare only what the provider actually does.** The eight providers do
 *      not offer the same operations, and a driver that pretends otherwise ends
 *      with a screen offering a button that calls an endpoint nobody has. What
 *      is not in {@see capabilities()} is refused rather than attempted, and a
 *      capability appears only when it has been implemented against that
 *      provider's own documentation.
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
     * Ask the gateway what became of **our** transaction (§28.1).
     *
     * Distinct from {@see verify()}, which is addressed by the provider's own
     * identifier. Reconciliation frequently has no such identifier — a payer who
     * closed the tab never sent one back — and "what happened to the transaction
     * I called X" is the only question that can be asked then.
     *
     * Only called when the provider declares {@see GatewayCapability::StatusQuery}.
     */
    public function status(string $reference): GatewayResult;

    /**
     * Give money back (§26.3).
     *
     * Only called when the provider declares the matching refund capability.
     * A refund the provider accepts but has not settled comes back as pending,
     * and pending is not refunded.
     */
    public function refund(RefundIntent $intent): RefundResult;

    /**
     * What became of a refund already submitted.
     */
    public function refundStatus(string $gatewayRefundReference): RefundResult;

    /**
     * The identifier used in config, logs, and the payments table.
     */
    public function name(): string;

    /**
     * Everything this provider can actually do here (§26.4).
     *
     * @return array<int, GatewayCapability>
     */
    public function capabilities(): array;

    /**
     * Whether one particular operation is available.
     */
    public function supports(GatewayCapability $capability): bool;

    /**
     * The currencies this provider will accept from this merchant (D4).
     *
     * Checked before a payment is offered: a gateway that cannot take the
     * currency of the amount is not a gateway the payer can use, whatever else
     * is configured.
     *
     * @return array<int, Currency>
     */
    public function supportedCurrencies(): array;

    /**
     * Whether this gateway is running against its sandbox (§26.4).
     */
    public function isSandbox(): bool;

    /**
     * Whether the credentials this gateway needs are actually present.
     *
     * Asked before the gateway is offered at checkout. A gateway switched on but
     * never credentialled would send somebody through package selection and the
     * whole fee breakdown only to fail on the last click — so "enabled" is not
     * enough on its own (§26.4).
     */
    public function isConfigured(): bool;

    /**
     * Every setting this provider cannot work without.
     *
     * Names only, never values — this is what the administration screen renders
     * a field for. Each provider needs a different set, and asking the driver
     * rather than hard-coding a form per provider is what keeps the screen and
     * the driver from drifting apart.
     *
     * @return array<int, string>
     */
    public function requiredConfiguration(): array;

    /**
     * Which of those settings are still empty.
     *
     * Names only, never values. The point of it is that "incomplete" should say
     * *what* is incomplete rather than leaving somebody to guess which of six
     * fields was missed.
     *
     * @return array<int, string>
     */
    public function missingConfiguration(): array;
}
