<?php

namespace App\Integrations\Payment\Gateways\PayPal;

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
 * PayPal Orders v2 (§26, D4, D7).
 *
 * Built against PayPal's published REST reference: an access token from client
 * credentials, an order carrying an approval link, a capture that completes it,
 * refunds against the capture, and PayPal's own endpoint for verifying a
 * webhook.
 *
 * **Shipped disabled** (P2-38), like Stripe. No merchant account exists, so
 * there is nothing to credential and it cannot be switched on.
 *
 * Four things shape this driver.
 *
 * **Capture is part of verification.** A PayPal order is created, approved by
 * the payer, and only then captured — so asking what happened may mean finishing
 * it. {@see verify()} reads the order first and captures only from `APPROVED`,
 * which makes a repeat call cheap and safe: an order already `COMPLETED` is read
 * rather than captured again.
 *
 * **Its webhook is verified by asking PayPal.** Rather than checking an RSA
 * signature locally against a certificate chain, PayPal publishes an endpoint
 * that takes the five transmission headers and the raw event and answers whether
 * it is genuine. That is the documented method, it needs no cryptography written
 * from an inference, and it is what this uses.
 *
 * **Only `COMPLETED` acts.** PayPal's public reference documents that value at
 * every level — order, capture and refund — without enumerating the rest in one
 * place. So this driver acts on the documented success and treats everything
 * else as not yet confirmed. Pending releases nothing, which is the right side
 * to be wrong on.
 *
 * **BDT is not offered** (D4), for the same reason as Stripe: the base ledger is
 * in taka and version 1 invents no exchange rate.
 */
class PayPalGateway extends Gateway
{
    public const SANDBOX_HOST = 'https://api-m.sandbox.paypal.com';

    public const LIVE_HOST = 'https://api-m.paypal.com';

    /** The one status this driver acts on, at every level. */
    public const COMPLETED = 'COMPLETED';

    /** The payer has approved; the money has not moved until it is captured. */
    public const APPROVED = 'APPROVED';

    /** PayPal's answer when a notification really is from PayPal. */
    public const VERIFICATION_SUCCESS = 'SUCCESS';

    /**
     * The headers PayPal's verification endpoint needs, and what it calls them.
     *
     * @var array<string, string>
     */
    public const SIGNATURE_HEADERS = [
        'transmission_id' => 'PAYPAL-TRANSMISSION-ID',
        'transmission_time' => 'PAYPAL-TRANSMISSION-TIME',
        'transmission_sig' => 'PAYPAL-TRANSMISSION-SIG',
        'cert_url' => 'PAYPAL-CERT-URL',
        'auth_algo' => 'PAYPAL-AUTH-ALGO',
    ];

    public function __construct(
        protected HttpClient $http,
        protected PayPalCredentials $credentials,
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
     * What PayPal can settle here without an exchange rate (D4).
     *
     * @return array<int, Currency>
     */
    public function supportedCurrencies(): array
    {
        return [Currency::USD, Currency::EUR, Currency::GBP];
    }

    public function initiate(PaymentIntent $intent): GatewayRedirect
    {
        $body = $this->call('post', '/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                // PayPal echoes this back, which is how the verified answer
                // names the payment it belongs to.
                'invoice_id' => $intent->reference,
                'description' => $intent->description,
                'amount' => [
                    'currency_code' => $intent->amount->currency->value,

                    // PayPal takes a decimal string, unlike Stripe.
                    'value' => $intent->amount->toDecimal(),
                ],
            ]],
            'application_context' => [
                'return_url' => $intent->successUrl,
                'cancel_url' => $intent->cancelUrl,
            ],
        ], idempotencyKey: 'order:'.$intent->reference);

        $id = $body['id'] ?? null;
        $url = $this->link($body, 'approve');

        if (! is_string($id) || $id === '' || $url === null) {
            throw GatewayUnavailable::forGateway($this->name(), $this->errorMessage($body, 'no order was created'));
        }

        return new GatewayRedirect(url: $url, gatewayReference: $id);
    }

    /**
     * Read the return request.
     *
     * PayPal returns with the order token and nothing that decides anything.
     * The money has not moved until the order is captured.
     */
    public function handleCallback(Request $request): GatewayResult
    {
        $orderId = $request->input('token');
        $orderId = is_string($orderId) && $orderId !== '' ? $orderId : null;

        if ($orderId === null) {
            return GatewayResult::failed(
                reference: null,
                error: 'The gateway returned without naming an order.',
                raw: $request->all(),
            );
        }

        return GatewayResult::pending(
            reference: '',
            gatewayReference: $orderId,
            raw: $request->all(),
        );
    }

    /**
     * Ask PayPal whether a notification is genuinely from PayPal (§26.4).
     *
     * The documented method: post the five transmission headers, the configured
     * webhook id, and the event back to PayPal, and believe only an explicit
     * `SUCCESS`. Using PayPal's own endpoint rather than checking the RSA
     * signature locally means no cryptography here is inferred from anything.
     *
     * `webhook_event` is sent as the **decoded raw body**, so what PayPal
     * verifies is what arrived rather than a re-encoding of it.
     *
     * Fails closed on a missing header, a missing webhook id, an unreachable
     * verification endpoint, or any answer that is not exactly `SUCCESS`.
     */
    public function verifyWebhookSignature(Request $request): bool
    {
        $payload = [];

        foreach (self::SIGNATURE_HEADERS as $field => $header) {
            $value = $request->header($header);

            if (! is_string($value) || $value === '') {
                return false;
            }

            $payload[$field] = $value;
        }

        $webhookId = $this->credentials->value('webhook_id');

        if ($webhookId === null) {
            return false;
        }

        /** @var mixed $event */
        $event = json_decode($request->getContent(), true);

        if (! is_array($event)) {
            return false;
        }

        $payload['webhook_id'] = $webhookId;
        $payload['webhook_event'] = $event;

        try {
            $body = $this->call('post', '/v1/notifications/verify-webhook-signature', $payload);
        } catch (GatewayUnavailable) {
            // Could not ask, so cannot conclude it is genuine. An unverifiable
            // notification is an anonymous request claiming money arrived.
            return false;
        }

        return ($body['verification_status'] ?? null) === self::VERIFICATION_SUCCESS;
    }

    /**
     * Read the order, capturing it if the payer has approved but nobody has.
     */
    public function verify(string $gatewayReference): GatewayResult
    {
        $order = $this->call('get', '/v2/checkout/orders/'.urlencode($gatewayReference), []);

        $status = (string) ($order['status'] ?? '');

        /*
         * Approved is the payer's half. The money does not move until the
         * merchant captures, and this is the only place that happens — from
         * `APPROVED` alone, so an order already completed is read rather than
         * captured a second time.
         */
        if ($status === self::APPROVED) {
            $order = $this->call(
                'post',
                '/v2/checkout/orders/'.urlencode($gatewayReference).'/capture',
                [],
                idempotencyKey: 'capture:'.$gatewayReference,
                throwOnError: false,
            );

            $status = (string) ($order['status'] ?? '');
        }

        $reference = $this->invoiceId($order);
        $capture = $this->capture($order);

        if ($status === self::COMPLETED && $capture !== null
            && (string) ($capture['status'] ?? '') === self::COMPLETED) {
            $amount = $capture['amount'] ?? null;

            if (! is_array($amount) || ! isset($amount['value'], $amount['currency_code'])) {
                throw GatewayUnavailable::malformedResponse($this->name());
            }

            return GatewayResult::paid(
                reference: $reference,
                gatewayReference: $gatewayReference,
                amount: Money::fromDecimal(
                    (string) $amount['value'],
                    Currency::from(mb_strtoupper((string) $amount['currency_code'])),
                ),
                raw: $order,

                // The capture id, which is what a refund is addressed to.
                settlementReference: isset($capture['id']) && is_string($capture['id'])
                    ? $capture['id']
                    : null,
            );
        }

        if (isset($order['error']) || isset($order['name'])) {
            return GatewayResult::failed(
                reference: $reference === '' ? null : $reference,
                error: $this->errorMessage($order, 'The gateway did not confirm this payment.'),
                errorCode: $this->errorCode($order),
                raw: $order,
                gatewayReference: $gatewayReference,
            );
        }

        // Created, approved-but-uncaptured, or anything this driver has no
        // documented meaning for. None of them released anything.
        return GatewayResult::pending($reference, $gatewayReference, $order);
    }

    /**
     * The same read. A PayPal order id is what it knows this by either way.
     */
    public function status(string $reference): GatewayResult
    {
        return $this->verify($reference);
    }

    /**
     * Return money through PayPal (§26.3).
     *
     * Addressed to the capture id, with our idempotency key in PayPal's own
     * `PayPal-Request-Id` header so a retried refund is the same refund.
     */
    public function refund(RefundIntent $intent): RefundResult
    {
        $this->requireCapability(
            $intent->isFull() ? GatewayCapability::RefundFull : GatewayCapability::RefundPartial,
        );

        $body = $this->call(
            'post',
            '/v2/payments/captures/'.urlencode($intent->gatewayReference).'/refund',
            [
                'amount' => [
                    'currency_code' => $intent->amount->currency->value,
                    'value' => $intent->amount->toDecimal(),
                ],
                'note_to_payer' => mb_substr($intent->reason, 0, 255),
                'invoice_id' => $intent->reference,
            ],
            idempotencyKey: $intent->idempotencyKey,
            throwOnError: false,
        );

        return $this->readRefund($body);
    }

    public function refundStatus(string $gatewayRefundReference): RefundResult
    {
        return $this->readRefund(
            $this->call('get', '/v2/payments/refunds/'.urlencode($gatewayRefundReference), [], throwOnError: false),
        );
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function readRefund(array $body): RefundResult
    {
        $reference = isset($body['id']) && is_string($body['id']) ? $body['id'] : null;

        if ($reference === null || isset($body['name']) || isset($body['error'])) {
            return RefundResult::failed(
                error: $this->errorMessage($body, 'The gateway refused this refund.'),
                errorCode: $this->errorCode($body),
                raw: $body,
                gatewayRefundReference: $reference,
            );
        }

        $amount = $body['amount'] ?? null;

        $money = is_array($amount) && isset($amount['value'], $amount['currency_code'])
            ? Money::fromDecimal(
                (string) $amount['value'],
                Currency::from(mb_strtoupper((string) $amount['currency_code'])),
            )
            : null;

        if ((string) ($body['status'] ?? '') === self::COMPLETED) {
            return RefundResult::succeeded($reference, $money, $body);
        }

        // Not the documented success, so not money returned.
        return RefundResult::pending($reference, $money, $body);
    }

    /**
     * The capture inside an order, when one exists.
     *
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>|null
     */
    protected function capture(array $order): ?array
    {
        $units = $order['purchase_units'] ?? null;

        if (! is_array($units)) {
            return null;
        }

        foreach ($units as $unit) {
            if (! is_array($unit) || ! isset($unit['payments'])) {
                continue;
            }

            $payments = $unit['payments'];

            if (! is_array($payments) || ! isset($payments['captures']) || ! is_array($payments['captures'])) {
                continue;
            }

            foreach ($payments['captures'] as $capture) {
                if (is_array($capture)) {
                    /** @var array<string, mixed> $capture */
                    return $capture;
                }
            }
        }

        return null;
    }

    /**
     * Our own reference, as PayPal echoes it back.
     *
     * @param  array<string, mixed>  $order
     */
    protected function invoiceId(array $order): string
    {
        $units = $order['purchase_units'] ?? null;

        if (! is_array($units)) {
            return '';
        }

        foreach ($units as $unit) {
            if (is_array($unit) && isset($unit['invoice_id']) && is_string($unit['invoice_id'])) {
                return $unit['invoice_id'];
            }
        }

        return '';
    }

    /**
     * A named link out of a HATEOAS response.
     *
     * @param  array<string, mixed>  $body
     */
    protected function link(array $body, string $rel): ?string
    {
        $links = $body['links'] ?? null;

        if (! is_array($links)) {
            return null;
        }

        foreach ($links as $link) {
            if (is_array($link) && ($link['rel'] ?? null) === $rel
                && isset($link['href']) && is_string($link['href'])) {
                return $link['href'];
            }
        }

        return null;
    }

    /**
     * One call to PayPal.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function call(string $method, string $path, array $payload, ?string $idempotencyKey = null, bool $throwOnError = true): array
    {
        $request = $this->http
            ->asJson()
            ->timeout(30)
            ->withToken($this->token())
            ->withHeaders(['Accept' => 'application/json']);

        if ($idempotencyKey !== null) {
            // PayPal's own replay protection.
            $request = $request->withHeaders(['PayPal-Request-Id' => $idempotencyKey]);
        }

        $response = $request->{$method}($this->host().$path, $payload);

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
     * Exchange the client credentials for an access token.
     */
    protected function token(): string
    {
        $this->requireConfigured();

        $response = $this->http
            ->asForm()
            ->timeout(30)
            ->withBasicAuth($this->credentials->clientId(), $this->credentials->clientSecret())
            ->post($this->host().'/v1/oauth2/token', ['grant_type' => 'client_credentials']);

        if ($response->failed()) {
            throw GatewayUnavailable::forGateway($this->name(), 'HTTP '.$response->status());
        }

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        $token = $body['access_token'] ?? null;

        if (! is_string($token) || $token === '') {
            throw GatewayUnavailable::forGateway($this->name(), 'authentication was refused');
        }

        return $token;
    }

    /**
     * PayPal reports problems as `name` and `message` rather than an `error`
     * object, so both shapes are read.
     *
     * @param  array<string, mixed>  $body
     */
    protected function errorMessage(array $body, string $fallback): string
    {
        if (isset($body['message']) && is_string($body['message']) && $body['message'] !== '') {
            return $body['message'];
        }

        return $fallback;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function errorCode(array $body): ?string
    {
        return isset($body['name']) && is_string($body['name']) ? $body['name'] : null;
    }

    protected function credentials(): GatewayCredentials
    {
        return $this->credentials;
    }

    protected function host(): string
    {
        return $this->isSandbox() ? self::SANDBOX_HOST : self::LIVE_HOST;
    }
}
