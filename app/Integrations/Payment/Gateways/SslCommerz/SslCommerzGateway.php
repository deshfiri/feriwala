<?php

namespace App\Integrations\Payment\Gateways\SslCommerz;

use App\Integrations\Payment\Contracts\PaymentGateway;
use App\Integrations\Payment\Data\GatewayRedirect;
use App\Integrations\Payment\Data\GatewayResult;
use App\Integrations\Payment\Data\PaymentIntent;
use App\Integrations\Payment\Exceptions\GatewayUnavailable;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Http\Request;
use Illuminate\Log\LogManager;

/**
 * SSLCommerz (D7 — the first gateway).
 *
 * SSLCommerz reports an outcome three ways, and they are not equally
 * trustworthy:
 *
 *   1. the browser redirect — anyone can forge it
 *   2. the IPN callback — genuine if the signature verifies
 *   3. the validation API — the only authoritative answer
 *
 * This driver treats 1 and 2 as notifications that something *may* have
 * happened, and always answers "did it?" with 3.
 */
class SslCommerzGateway implements PaymentGateway
{
    public const SANDBOX_HOST = 'https://sandbox.sslcommerz.com';

    public const LIVE_HOST = 'https://securepay.sslcommerz.com';

    public function __construct(
        protected HttpClient $http,
        protected LogManager $log,
        protected SslCommerzCredentials $credentials,
    ) {}

    public function name(): string
    {
        return 'sslcommerz';
    }

    public function isSandbox(): bool
    {
        return $this->credentials->isSandbox();
    }

    public function initiate(PaymentIntent $intent): GatewayRedirect
    {
        $response = $this->http
            ->asForm()
            ->timeout(20)
            ->post($this->host().'/gwprocess/v4/api.php', [
                'store_id' => $this->credentials->storeId(),
                'store_passwd' => $this->credentials->storePassword(),

                // SSLCommerz expects a decimal string, not minor units. This is
                // the one place the conversion happens.
                'total_amount' => $intent->amount->toDecimal(),
                'currency' => $intent->amount->currency->value,

                'tran_id' => $intent->reference,

                'success_url' => $intent->successUrl,
                'fail_url' => $intent->failUrl,
                'cancel_url' => $intent->cancelUrl,
                'ipn_url' => $intent->ipnUrl,

                'cus_name' => $intent->customerName,
                'cus_email' => $intent->customerEmail,
                'cus_phone' => $intent->customerMobile ?? '',

                'product_name' => $intent->description,
                'product_category' => 'service',
                'product_profile' => 'non-physical-goods',

                // Required by SSLCommerz even for non-shipped goods.
                'shipping_method' => 'NO',
                'num_of_item' => 1,
            ]);

        if ($response->failed()) {
            throw GatewayUnavailable::forGateway($this->name(), 'HTTP '.$response->status());
        }

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        if (($body['status'] ?? null) !== 'SUCCESS' || empty($body['GatewayPageURL'])) {
            $this->log->channel('payment')->warning('SSLCommerz refused to start a session', [
                'reference' => $intent->reference,
                'status' => $body['status'] ?? null,
                'reason' => $body['failedreason'] ?? null,
            ]);

            throw GatewayUnavailable::forGateway(
                $this->name(),
                (string) ($body['failedreason'] ?? 'session could not be created'),
            );
        }

        return new GatewayRedirect(
            url: (string) $body['GatewayPageURL'],
            gatewayReference: isset($body['sessionkey']) ? (string) $body['sessionkey'] : null,
        );
    }

    /**
     * Read the return request.
     *
     * Deliberately does **not** conclude the payment succeeded, whatever the
     * request says — a user can edit anything in that redirect. It reports
     * pending on an apparent success so the caller is forced through verify().
     */
    public function handleCallback(Request $request): GatewayResult
    {
        $reference = $request->input('tran_id');
        $status = (string) $request->input('status', '');

        if ($status === 'VALID' || $status === 'VALIDATED') {
            return GatewayResult::pending(
                reference: (string) $reference,
                gatewayReference: $request->input('val_id'),
                raw: $request->all(),
            );
        }

        if ($status === 'CANCELLED') {
            return GatewayResult::cancelled((string) $reference, $request->all());
        }

        return GatewayResult::failed(
            reference: $reference === null ? null : (string) $reference,
            error: (string) $request->input('error', 'Payment was not completed.'),
            errorCode: $status !== '' ? $status : null,
            raw: $request->all(),
        );
    }

    /**
     * Verify an IPN's signature (§17.3, §26.4).
     *
     * SSLCommerz builds `verify_sign` as md5 of an alphabetically ordered
     * key=value string, with the store password hashed in. Rebuilding it from
     * the fields it names — rather than from everything posted — is what stops
     * an attacker adding fields to change the outcome while keeping the
     * signature valid.
     *
     * Fails closed on anything missing or malformed.
     */
    public function verifyWebhookSignature(Request $request): bool
    {
        $providedSignature = $request->input('verify_sign');
        $verifyKey = $request->input('verify_key');

        if (! is_string($providedSignature) || ! is_string($verifyKey) || $verifyKey === '') {
            return false;
        }

        // Only the fields SSLCommerz itself lists are signed.
        $keys = explode(',', $verifyKey);

        $pairs = [];

        foreach ($keys as $key) {
            $key = trim($key);

            if ($key === '') {
                continue;
            }

            $pairs[$key] = (string) $request->input($key, '');
        }

        if ($pairs === []) {
            return false;
        }

        $pairs['store_passwd'] = md5($this->credentials->storePassword());

        ksort($pairs);

        $built = [];

        foreach ($pairs as $key => $value) {
            $built[] = $key.'='.$value;
        }

        $expected = md5(implode('&', $built));

        // Constant time: a timing-variable comparison leaks the signature one
        // byte at a time.
        return hash_equals($expected, $providedSignature);
    }

    /**
     * Ask SSLCommerz directly. The authoritative answer.
     */
    public function verify(string $gatewayReference): GatewayResult
    {
        $response = $this->http
            ->timeout(20)
            ->get($this->host().'/validator/api/validationserverAPI.php', [
                'val_id' => $gatewayReference,
                'store_id' => $this->credentials->storeId(),
                'store_passwd' => $this->credentials->storePassword(),
                'format' => 'json',
            ]);

        if ($response->failed()) {
            throw GatewayUnavailable::forGateway($this->name(), 'HTTP '.$response->status());
        }

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        $status = (string) ($body['status'] ?? '');
        $reference = (string) ($body['tran_id'] ?? '');

        // VALID means settled; VALIDATED means settled and already acknowledged.
        if ($status === 'VALID' || $status === 'VALIDATED') {
            if (! isset($body['currency_amount'], $body['currency_type'])) {
                throw GatewayUnavailable::malformedResponse($this->name());
            }

            return GatewayResult::paid(
                reference: $reference,
                gatewayReference: $gatewayReference,
                amount: Money::fromDecimal(
                    (string) $body['currency_amount'],
                    Currency::from((string) $body['currency_type']),
                ),
                raw: $body,
            );
        }

        if ($status === 'PENDING' || $status === 'PROCESSING') {
            return GatewayResult::pending($reference, $gatewayReference, $body);
        }

        return GatewayResult::failed(
            reference: $reference === '' ? null : $reference,
            error: (string) ($body['error'] ?? 'The gateway reported the payment as '.($status ?: 'unknown').'.'),
            errorCode: $status !== '' ? $status : null,
            raw: $body,
        );
    }

    protected function host(): string
    {
        return $this->isSandbox() ? self::SANDBOX_HOST : self::LIVE_HOST;
    }
}
