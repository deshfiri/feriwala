<?php

namespace App\Integrations\Payment\Gateways\SurjoPay;

use App\Integrations\Payment\Data\GatewayCapability;
use App\Integrations\Payment\Data\GatewayRedirect;
use App\Integrations\Payment\Data\GatewayResult;
use App\Integrations\Payment\Data\PaymentIntent;
use App\Integrations\Payment\Exceptions\GatewayUnavailable;
use App\Integrations\Payment\Gateways\Gateway;
use App\Integrations\Payment\Gateways\GatewayCredentials;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Http\Request;

/**
 * shurjoPay (§26, D7).
 *
 * Built against shurjoPay's published REST documentation: a token obtained from
 * an API username and password, a checkout call that returns a URL to send the
 * payer to, and a verification call that is the authoritative answer.
 *
 * Two things about this provider shape the driver:
 *
 *   1. **Its notification is unsigned, on purpose.** shurjoPay's IPN carries the
 *      order id and nothing else, and its own documentation says to verify
 *      through the API on receipt. So there is no signature capability here to
 *      declare — not a gap, but how the provider works. Nothing in a
 *      notification decides anything; {@see verify()} does.
 *   2. **Verification is addressed by shurjoPay's order id**, not ours. Their
 *      `order_id` is the provider's own identifier and `customer_order_id` is
 *      the one we sent, which is why the callback's order id is what gets
 *      stored and passed back here.
 *
 * No refund API is documented, so none is declared. The screen offers no refund
 * button for this provider rather than a button that calls nothing.
 */
class SurjoPayGateway extends Gateway
{
    public const SANDBOX_HOST = 'https://sandbox.shurjopayment.com';

    public const LIVE_HOST = 'https://engine.shurjopayment.com';

    /** The only sp_code that means the money arrived. */
    public const CODE_SUCCESS = '1000';

    /** Declined by the customer's issuing bank. */
    public const CODE_DECLINED = '1001';

    /** Cancelled by the customer. */
    public const CODE_CANCELLED = '1002';

    /**
     * What goes in shurjoPay's mandatory address fields.
     *
     * Said plainly rather than filled with something plausible, because a
     * plausible address is one that reads as the payer's on a receipt.
     */
    public const ADDRESS_NOT_COLLECTED = 'Not collected';

    public function __construct(
        protected HttpClient $http,
        protected SurjoPayCredentials $credentials,
    ) {}

    /**
     * @return array<int, GatewayCapability>
     */
    public function capabilities(): array
    {
        return [
            GatewayCapability::Initiate,
            GatewayCapability::Verify,
        ];
    }

    public function initiate(PaymentIntent $intent): GatewayRedirect
    {
        $token = $this->token();

        $response = $this->http
            ->asMultipart()
            ->timeout(20)
            ->withToken($token['token'])
            ->post($this->host().'/api/secret-pay', $this->multipart([
                'prefix' => $this->credentials->prefix(),
                'token' => $token['token'],
                'store_id' => $token['store_id'],

                // A decimal string, like every other provider. The conversion
                // from minor units happens here and nowhere else.
                'amount' => $intent->amount->toDecimal(),
                'currency' => $intent->amount->currency->value,
                'order_id' => $intent->reference,

                'return_url' => $intent->successUrl,
                'cancel_url' => $intent->cancelUrl,

                'customer_name' => $intent->customerName,
                'customer_email' => $intent->customerEmail,
                'customer_phone' => $intent->customerMobile ?? '',

                /*
                 * Mandatory for shurjoPay, and meaningless here. These are
                 * billing-address fields for shipped goods; nothing in this
                 * batch ships anything, and no billing address is collected for
                 * an account service. Sent as a stated placeholder rather than
                 * filled with something that would read as the payer's own
                 * address on a receipt they never gave one for.
                 */
                'customer_address' => self::ADDRESS_NOT_COLLECTED,
                'customer_city' => self::ADDRESS_NOT_COLLECTED,
            ]));

        if ($response->failed()) {
            throw GatewayUnavailable::forGateway($this->name(), 'HTTP '.$response->status());
        }

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        $url = $body['checkout_url'] ?? null;

        if (! is_string($url) || $url === '') {
            throw GatewayUnavailable::forGateway(
                $this->name(),
                (string) ($body['message'] ?? 'no checkout url was returned'),
            );
        }

        return new GatewayRedirect(
            url: $url,
            // shurjoPay's own order id, which is what verification is addressed
            // to. Ours travels back as `customer_order_id`.
            gatewayReference: isset($body['sp_order_id']) && $body['sp_order_id'] !== ''
                ? (string) $body['sp_order_id']
                : null,
        );
    }

    /**
     * Read the return request.
     *
     * Reports pending on anything that looks like success, never paid: the
     * browser is not evidence, and the order id is only what tells us which
     * transaction to go and ask about.
     */
    public function handleCallback(Request $request): GatewayResult
    {
        $orderId = $request->input('order_id');
        $orderId = is_string($orderId) && $orderId !== '' ? $orderId : null;

        if ($orderId === null) {
            return GatewayResult::failed(
                reference: null,
                error: 'The gateway returned without naming a transaction.',
                raw: $request->all(),
            );
        }

        return GatewayResult::pending(
            reference: (string) $request->input('customer_order_id', ''),
            gatewayReference: $orderId,
            raw: $request->all(),
        );
    }

    /**
     * shurjoPay does not sign its notifications.
     *
     * Documented behaviour rather than an omission here: the IPN carries an
     * order id, and shurjoPay's own guidance is to verify through the API on
     * receiving one. Returning false keeps that honest — nothing is trusted
     * because it arrived, and {@see GatewayCapability::WebhookSignature} is
     * absent from this driver's capabilities for the same reason.
     */
    public function verifyWebhookSignature(Request $request): bool
    {
        return false;
    }

    /**
     * Ask shurjoPay directly. The authoritative answer.
     *
     * The response is an **array** of records for the order rather than a single
     * object, so the successful one is looked for explicitly.
     */
    public function verify(string $gatewayReference): GatewayResult
    {
        $token = $this->token();

        $response = $this->http
            ->timeout(20)
            ->withToken($token['token'])
            ->post($this->host().'/api/verification', ['order_id' => $gatewayReference]);

        if ($response->failed()) {
            throw GatewayUnavailable::forGateway($this->name(), 'HTTP '.$response->status());
        }

        /** @var array<int|string, mixed> $body */
        $body = $response->json() ?? [];

        $record = $this->firstRecord($body);

        if ($record === null) {
            return GatewayResult::failed(
                reference: null,
                error: 'The gateway has no record of this transaction.',
                errorCode: 'not_found',
                raw: [],
            );
        }

        $code = (string) ($record['sp_code'] ?? '');
        $reference = (string) ($record['customer_order_id'] ?? '');

        if ($code === self::CODE_SUCCESS) {
            if (! isset($record['amount'], $record['currency'])) {
                throw GatewayUnavailable::malformedResponse($this->name());
            }

            return GatewayResult::paid(
                reference: $reference,
                gatewayReference: $gatewayReference,
                amount: Money::fromDecimal(
                    (string) $record['amount'],
                    Currency::from((string) $record['currency']),
                ),
                raw: $record,
                settlementReference: $this->bankTransactionId($record),
            );
        }

        if ($code === self::CODE_CANCELLED) {
            return GatewayResult::cancelled($reference === '' ? null : $reference, $record);
        }

        /*
         * Anything that is not one of the three documented codes has not been
         * confirmed, and an unconfirmed transaction is pending rather than
         * failed — marking it failed would abandon money that may still be on
         * its way. Only a documented decline closes it.
         */
        if ($code !== self::CODE_DECLINED) {
            return GatewayResult::pending($reference, $gatewayReference, $record);
        }

        return GatewayResult::failed(
            reference: $reference === '' ? null : $reference,
            error: (string) ($record['sp_message'] ?? 'The gateway did not confirm this payment.'),
            errorCode: $code,
            raw: $record,
            gatewayReference: $gatewayReference,
        );
    }

    /**
     * Authenticate, and keep what the token call tells us.
     *
     * The store id is part of that answer rather than something configured, so
     * it travels with the token instead of being stored a second time.
     *
     * @return array{token: string, store_id: string}
     */
    protected function token(): array
    {
        // Before the call, not after it: a driver that authenticates and then
        // finds it has no prefix has reported the wrong problem.
        $this->requireConfigured();

        $response = $this->http
            ->timeout(20)
            ->post($this->host().'/api/get_token', [
                'username' => $this->credentials->username(),
                'password' => $this->credentials->password(),
            ]);

        if ($response->failed()) {
            throw GatewayUnavailable::forGateway($this->name(), 'HTTP '.$response->status());
        }

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        $token = $body['token'] ?? null;
        $storeId = $body['store_id'] ?? null;

        if (! is_string($token) || $token === '' || $storeId === null) {
            throw GatewayUnavailable::forGateway($this->name(), 'authentication was refused');
        }

        return ['token' => $token, 'store_id' => (string) $storeId];
    }

    /**
     * The first usable record in a verification response.
     *
     * @param  array<int|string, mixed>  $body
     * @return array<string, mixed>|null
     */
    protected function firstRecord(array $body): ?array
    {
        foreach ($body as $record) {
            if (is_array($record) && $record !== []) {
                /** @var array<string, mixed> $record */
                return $record;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    protected function bankTransactionId(array $record): ?string
    {
        $id = $record['bank_trx_id'] ?? null;

        // "0" is what this field holds when no bank transaction happened.
        return is_string($id) && $id !== '' && $id !== '0' ? $id : null;
    }

    /**
     * shurjoPay's checkout call takes multipart form data.
     *
     * @param  array<string, string>  $fields
     * @return list<array{name: string, contents: string}>
     */
    protected function multipart(array $fields): array
    {
        $parts = [];

        foreach ($fields as $name => $contents) {
            $parts[] = ['name' => $name, 'contents' => $contents];
        }

        return $parts;
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
