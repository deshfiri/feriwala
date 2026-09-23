<?php

namespace App\Integrations\Payment\Gateways\Stripe;

use App\Integrations\Payment\Data\GatewayCapability;
use App\Integrations\Payment\Data\GatewayRedirect;
use App\Integrations\Payment\Data\GatewayResult;
use App\Integrations\Payment\Data\PaymentIntent;
use App\Integrations\Payment\Data\RefundIntent;
use App\Integrations\Payment\Data\RefundResult;
use App\Integrations\Payment\Exceptions\GatewayUnavailable;
use App\Integrations\Payment\Gateways\Gateway;
use App\Integrations\Payment\Gateways\GatewayCredentials;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Http\Request;

/**
 * Stripe Checkout (§26, D4, D7).
 *
 * Built against Stripe's published API reference: a Checkout Session returning a
 * hosted payment page, the same session retrieved as the authoritative answer,
 * and refunds against the session's PaymentIntent.
 *
 * **Shipped disabled** (P2-38). There is no Stripe merchant account, so there is
 * nothing to credential, and a gateway cannot be switched on without complete
 * credentials. The driver exists so that the day an account is opened is a
 * configuration day rather than a development one.
 *
 * Three things are specific to this provider.
 *
 * **Amounts cross to Stripe's own smallest-unit convention, and nowhere else.**
 * Stripe's `unit_amount` is "a positive integer in the smallest currency unit"
 * — cents, for every currency this driver supports. {@see Money} holds exact
 * decimal Taka-equivalent major units (D26), so {@see toSmallestUnit()} and
 * {@see fromSmallestUnit()} are the one deliberate ×100/÷100 boundary in this
 * file: not a reintroduction of the old internal minor-unit convention, but
 * the external wire format of a provider this platform does not control. Both
 * helpers work in bcmath, never a float.
 *
 * **BDT is not offered here** (D4). The base ledger is in taka and version 1
 * performs no exchange-rate accounting, so a Stripe payment cannot be a
 * conversion of a taka amount. Declaring only the currencies this driver can
 * settle without inventing a rate means the gateway is simply never offered for
 * an amount it would have to convert — refused before initiation rather than
 * reconciled afterwards.
 *
 * **There is no sandbox host.** The key decides which environment a call
 * reaches, which makes "sandbox credentials must not operate against live
 * endpoints" a question about the key rather than the URL — see
 * {@see missingConfiguration()}.
 */
class StripeGateway extends Gateway
{
    public const HOST = 'https://api.stripe.com';

    /** Checkout says the money arrived. */
    public const PAYMENT_STATUS_PAID = 'paid';

    /** The session was never completed and cannot be now. */
    public const SESSION_STATUS_EXPIRED = 'expired';

    /** The refund has settled. */
    public const REFUND_SUCCEEDED = 'succeeded';

    /**
     * The two refund statuses that will not become a refund.
     *
     * Stripe's documented set is pending, requires_action, succeeded, failed and
     * canceled. The first two are still in flight.
     *
     * @var array<int, string>
     */
    public const REFUND_UNRECOVERABLE = ['failed', 'canceled'];

    /**
     * How stale a signed webhook may be, in seconds.
     *
     * Stripe's own libraries default to five minutes. The timestamp is inside
     * the signed payload, so an attacker cannot move it — this is what stops a
     * captured-and-replayed notification being accepted forever.
     */
    public const SIGNATURE_TOLERANCE = 300;

    public function __construct(
        protected HttpClient $http,
        protected StripeCredentials $credentials,
    ) {}

    /**
     * @return array<int, GatewayCapability>
     */
    public function capabilities(): array
    {
        return [
            GatewayCapability::Initiate,
            GatewayCapability::Verify,
            GatewayCapability::WebhookSignature,
            GatewayCapability::StatusQuery,
            GatewayCapability::RefundFull,
            GatewayCapability::RefundPartial,
            GatewayCapability::RefundStatus,
        ];
    }

    /**
     * What Stripe can settle here without an exchange rate (D4).
     *
     * @return array<int, Currency>
     */
    public function supportedCurrencies(): array
    {
        return [Currency::USD, Currency::EUR, Currency::GBP];
    }

    /**
     * A key stored under the wrong mode counts as missing.
     *
     * Stripe has no separate sandbox host, so nothing but the key itself stops a
     * test configuration reaching live. A `sk_live_` key sitting under the
     * sandbox mode would take real money from a test run, and a `sk_test_` key
     * under live would take none at all from a real customer. Both are reported
     * the same way the screen already reports anything else that is not ready.
     *
     * @return array<int, string>
     */
    public function missingConfiguration(): array
    {
        $missing = parent::missingConfiguration();

        if (! in_array('secret_key', $missing, true) && $this->misconfiguredMode()) {
            $missing[] = 'secret_key';
        }

        return $missing;
    }

    /**
     * Whether the stored key belongs to a different environment than the mode.
     */
    public function misconfiguredMode(): bool
    {
        $key = $this->credentials->value('secret_key');

        if ($key === null) {
            return false;
        }

        return $this->isSandbox()
            ? ! str_starts_with($key, StripeCredentials::TEST_KEY_PREFIX)
            : ! str_starts_with($key, StripeCredentials::LIVE_KEY_PREFIX);
    }

    public function initiate(PaymentIntent $intent): GatewayRedirect
    {
        $body = $this->call('post', '/v1/checkout/sessions', [
            'mode' => 'payment',

            'success_url' => $intent->successUrl,
            'cancel_url' => $intent->cancelUrl,

            // Stripe echoes this back on the session, which is how the verified
            // answer names the payment it belongs to.
            'client_reference_id' => $intent->reference,
            'customer_email' => $intent->customerEmail,

            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => mb_strtolower($intent->amount->currency->value),
                    'unit_amount' => $this->toSmallestUnit($intent->amount),
                    'product_data' => ['name' => $intent->description],
                ],
            ]],

            'metadata' => ['reference' => $intent->reference],
        ], idempotencyKey: 'session:'.$intent->reference);

        $url = $body['url'] ?? null;
        $id = $body['id'] ?? null;

        if (! is_string($url) || $url === '' || ! is_string($id) || $id === '') {
            throw GatewayUnavailable::forGateway($this->name(), $this->errorMessage($body, 'no session was created'));
        }

        return new GatewayRedirect(url: $url, gatewayReference: $id);
    }

    /**
     * Read the return request.
     *
     * Stripe's success URL carries nothing but what we put in it, so this
     * establishes identity only — the session has to be retrieved before
     * anything is concluded.
     */
    public function handleCallback(Request $request): GatewayResult
    {
        $sessionId = $request->input('session_id');
        $sessionId = is_string($sessionId) && $sessionId !== '' ? $sessionId : null;

        if ($sessionId === null) {
            return GatewayResult::failed(
                reference: null,
                error: 'The gateway returned without naming a session.',
                raw: $request->all(),
            );
        }

        return GatewayResult::pending(
            reference: '',
            gatewayReference: $sessionId,
            raw: $request->all(),
        );
    }

    /**
     * Verify a webhook against the **raw** request body (§26.4).
     *
     * Stripe's documented scheme: the `Stripe-Signature` header carries a
     * timestamp as `t=` and one or more signatures by scheme prefix; the signed
     * payload is the timestamp, a full stop, and the request body exactly as
     * sent; the signature is HMAC-SHA256 of that under the endpoint's signing
     * secret.
     *
     * Three details are load-bearing and none are optional:
     *
     *   - **The raw body.** Any reserialisation — reordered keys, changed
     *     whitespace, a different encoding — produces a different hash.
     *   - **Only the `v1` scheme.** Stripe also sends a fake `v0` for test
     *     events; accepting any scheme offered is a downgrade attack waiting to
     *     happen.
     *   - **The timestamp.** It is inside the signed payload, so it cannot be
     *     moved — which is exactly what makes checking its age the defence
     *     against a captured notification being replayed forever.
     *
     * Fails closed on anything malformed, missing or stale.
     */
    public function verifyWebhookSignature(Request $request): bool
    {
        $header = $request->header('Stripe-Signature');

        if (! is_string($header) || $header === '') {
            return false;
        }

        $timestamp = null;

        /** @var array<int, string> $signatures */
        $signatures = [];

        foreach (explode(',', $header) as $element) {
            $pair = explode('=', trim($element), 2);

            if (count($pair) !== 2) {
                continue;
            }

            [$prefix, $value] = $pair;

            if ($prefix === 't') {
                $timestamp = $value;
            }

            // Everything that is not v1 is discarded, including Stripe's own
            // v0 test scheme.
            if ($prefix === 'v1') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || ! ctype_digit($timestamp) || $signatures === []) {
            return false;
        }

        if (abs(time() - (int) $timestamp) > self::SIGNATURE_TOLERANCE) {
            return false;
        }

        $secret = $this->credentials->value('webhook_secret');

        if ($secret === null) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        foreach ($signatures as $signature) {
            // Constant time: a timing-variable comparison leaks the expected
            // signature one byte at a time.
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Retrieve the session. The authoritative answer.
     */
    public function verify(string $gatewayReference): GatewayResult
    {
        $body = $this->call('get', '/v1/checkout/sessions/'.urlencode($gatewayReference), []);

        $reference = isset($body['client_reference_id']) && is_string($body['client_reference_id'])
            ? $body['client_reference_id']
            : '';

        if ((string) ($body['payment_status'] ?? '') === self::PAYMENT_STATUS_PAID) {
            if (! isset($body['amount_total'], $body['currency'])) {
                throw GatewayUnavailable::malformedResponse($this->name());
            }

            return GatewayResult::paid(
                reference: $reference,
                gatewayReference: $gatewayReference,

                amount: $this->fromSmallestUnit(
                    (int) $body['amount_total'],
                    Currency::from(mb_strtoupper((string) $body['currency'])),
                ),
                raw: $body,

                // The PaymentIntent, which is what a refund is addressed to.
                settlementReference: isset($body['payment_intent']) && is_string($body['payment_intent'])
                    ? $body['payment_intent']
                    : null,
            );
        }

        if ((string) ($body['status'] ?? '') === self::SESSION_STATUS_EXPIRED) {
            return GatewayResult::failed(
                reference: $reference === '' ? null : $reference,
                error: 'The checkout session expired before it was paid.',
                errorCode: self::SESSION_STATUS_EXPIRED,
                raw: $body,
                gatewayReference: $gatewayReference,
            );
        }

        // Open, or complete with an asynchronous payment method still settling.
        return GatewayResult::pending($reference, $gatewayReference, $body);
    }

    /**
     * The same retrieval. A Checkout Session id is what Stripe knows this by,
     * whether it is being settled or swept.
     */
    public function status(string $reference): GatewayResult
    {
        return $this->verify($reference);
    }

    /**
     * Return money through Stripe (§26.3).
     *
     * Addressed to the PaymentIntent rather than the session, and carrying our
     * idempotency key as Stripe's own `Idempotency-Key` header — which is what
     * makes a retried refund the same refund rather than a second one.
     */
    public function refund(RefundIntent $intent): RefundResult
    {
        $this->requireCapability(
            $intent->isFull() ? GatewayCapability::RefundFull : GatewayCapability::RefundPartial,
        );

        $body = $this->call('post', '/v1/refunds', [
            'payment_intent' => $intent->gatewayReference,
            'amount' => $this->toSmallestUnit($intent->amount),
            'metadata' => ['reference' => $intent->reference, 'reason' => $intent->reason],
        ], idempotencyKey: $intent->idempotencyKey, throwOnError: false);

        return $this->readRefund($body);
    }

    public function refundStatus(string $gatewayRefundReference): RefundResult
    {
        return $this->readRefund(
            $this->call('get', '/v1/refunds/'.urlencode($gatewayRefundReference), [], throwOnError: false),
        );
    }

    /**
     * Turn a refund object into a result.
     *
     * @param  array<string, mixed>  $body
     */
    protected function readRefund(array $body): RefundResult
    {
        $reference = isset($body['id']) && is_string($body['id']) ? $body['id'] : null;
        $status = (string) ($body['status'] ?? '');

        if (isset($body['error']) || $reference === null) {
            return RefundResult::failed(
                error: $this->errorMessage($body, 'The gateway refused this refund.'),
                errorCode: $this->errorCode($body),
                raw: $body,
            );
        }

        $amount = isset($body['amount'], $body['currency'])
            ? $this->fromSmallestUnit((int) $body['amount'], Currency::from(mb_strtoupper((string) $body['currency'])))
            : null;

        if ($status === self::REFUND_SUCCEEDED) {
            return RefundResult::succeeded($reference, $amount, $body);
        }

        if (in_array($status, self::REFUND_UNRECOVERABLE, true)) {
            return RefundResult::failed(
                error: (string) ($body['failure_reason'] ?? 'The refund did not complete.'),
                errorCode: $status,
                raw: $body,
                gatewayRefundReference: $reference,
            );
        }

        // pending or requires_action: accepted, not settled.
        return RefundResult::pending($reference, $amount, $body);
    }

    /**
     * One call to Stripe.
     *
     * Stripe reports refusals as 4xx with an `error` object rather than as a
     * transport failure, and the two mean different things: a declined refund is
     * a result, while an unreachable gateway is not an answer at all. So the
     * callers that can act on a refusal ask for the body, and the rest throw.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function call(string $method, string $path, array $payload, ?string $idempotencyKey = null, bool $throwOnError = true): array
    {
        $this->requireConfigured();

        $request = $this->http
            ->asForm()
            ->timeout(30)
            ->withToken($this->credentials->secretKey());

        if ($idempotencyKey !== null) {
            // Stripe's own replay protection: the same key returns the first
            // result rather than performing the operation again.
            $request = $request->withHeaders(['Idempotency-Key' => $idempotencyKey]);
        }

        $response = $request->{$method}(self::HOST.$path, $payload);

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        if ($response->failed() && ($throwOnError || $body === [])) {
            if ($response->serverError() || $body === []) {
                throw GatewayUnavailable::forGateway($this->name(), 'HTTP '.$response->status());
            }

            throw GatewayUnavailable::forGateway($this->name(), $this->errorMessage($body, 'the request was refused'));
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function errorMessage(array $body, string $fallback): string
    {
        $error = $body['error'] ?? null;

        if (is_array($error) && isset($error['message']) && is_string($error['message'])) {
            return $error['message'];
        }

        return $fallback;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function errorCode(array $body): ?string
    {
        $error = $body['error'] ?? null;

        if (is_array($error) && isset($error['code']) && is_string($error['code'])) {
            return $error['code'];
        }

        return null;
    }

    protected function credentials(): GatewayCredentials
    {
        return $this->credentials;
    }

    /**
     * A `Money` as Stripe's own `unit_amount` — an integer count of the
     * currency's smallest unit (cents). The one deliberate ×100-shaped
     * boundary in this file: Stripe's wire format, not Feriwala's internal
     * one, and computed in bcmath so it is exact for every magnitude a
     * `NUMERIC(19,2)` column can hold.
     */
    protected function toSmallestUnit(Money $amount): int
    {
        return (int) bcmul($amount->toDecimal(), $this->unitsPerSmallestUnit($amount->currency), 0);
    }

    /**
     * The inverse of {@see toSmallestUnit()} — Stripe's integer smallest-unit
     * amount back to exact decimal-Taka-equivalent `Money`.
     */
    protected function fromSmallestUnit(int $amount, Currency $currency): Money
    {
        return Money::fromDecimal(
            bcdiv((string) $amount, $this->unitsPerSmallestUnit($currency), $currency->scale()),
            $currency,
        );
    }

    /**
     * How many of the currency's smallest units make one major unit — derived
     * from {@see Currency::smallestUnit()} rather than a hardcoded 100, so a
     * currency of a different scale would not need this file to change.
     */
    /**
     * @return numeric-string
     */
    private function unitsPerSmallestUnit(Currency $currency): string
    {
        return bcdiv('1', $currency->smallestUnit(), 0);
    }
}
