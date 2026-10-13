<?php

namespace App\Integrations\Payment\Gateways\Eps;

use App\Domain\Billing\Actions\RecordGatewaySession;
use App\Integrations\Payment\Contracts\TestsConnection;
use App\Integrations\Payment\Data\GatewayCapability;
use App\Integrations\Payment\Data\GatewayRedirect;
use App\Integrations\Payment\Data\GatewayResult;
use App\Integrations\Payment\Data\PaymentIntent;
use App\Integrations\Payment\Exceptions\GatewayUnavailable;
use App\Integrations\Payment\Gateways\Gateway;
use App\Integrations\Payment\Gateways\GatewayCredentials;
use App\Support\Concurrency\DistributedLock;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Log\LogManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * EPS — Easy Payment System (§26, D7).
 *
 * Built from, and only from, the EPS Merchant API Integration Guide v0.5
 * (January 2024), which documents exactly three calls on one host
 * (`https://pgapi.eps.com.bd`):
 *
 *   - `POST /v1/Auth/GetToken` — `x-hash` is the HMAC of the **username**;
 *   - `POST /v1/EPSEngine/InitializeEPS` — `x-hash` is the HMAC of the
 *     **merchantTransactionId**, with the bearer token from GetToken;
 *   - `GET  /v1/EPSEngine/CheckMerchantTransactionStatus` — the same hash over
 *     the identifier being asked about.
 *
 * where the HMAC is `base64(hmac_sha512($data, $hashKey))`. Every hash and the
 * token are produced here, per request; nobody ever pastes either one.
 *
 * The guide has no sandbox host and no IPN. The base URL is therefore a stored,
 * validated setting ({@see EpsCredentials::baseUrl()}) and a mode without one is
 * unconfigured, and {@see verifyWebhookSignature()} refuses everything: nothing
 * here releases value except a direct status check (§26.4).
 *
 * Nothing sensitive is logged — never the token, a hash, a credential, the
 * payer or a payload. See {@see logCall()}.
 */
class EpsGateway extends Gateway implements TestsConnection
{
    protected const GET_TOKEN_PATH = '/v1/Auth/GetToken';

    protected const INITIALIZE_PATH = '/v1/EPSEngine/InitializeEPS';

    protected const STATUS_PATH = '/v1/EPSEngine/CheckMerchantTransactionStatus';

    /** The guide's `TransectionTypeId`: 1 = Web. */
    protected const WEB_TRANSACTION_TYPE = 1;

    /** Refresh this long before a token expires. */
    protected const TOKEN_SKEW_SECONDS = 60;

    /** Used only when a token carries no readable expiry. */
    protected const TOKEN_FALLBACK_SECONDS = 300;

    public function __construct(
        protected HttpClient $http,
        protected LogManager $log,
        protected EpsCredentials $credentials,
        protected Cache $cache,
        protected DistributedLock $lock,
        protected Encrypter $encrypter,
        protected RecordGatewaySession $sessions,
    ) {}

    /**
     * @return array<int, GatewayCapability>
     */
    public function capabilities(): array
    {
        return [
            GatewayCapability::Initiate,
            GatewayCapability::Verify,
            GatewayCapability::StatusQuery,
        ];
    }

    public function testConnection(): void
    {
        $this->requireConfigured();

        $this->token(forceRefresh: true);
    }

    public function initiate(PaymentIntent $intent): GatewayRedirect
    {
        $this->requireConfigured();

        $address = $intent->payerAddress
            ?? throw GatewayUnavailable::missingPayerAddress($this->name(), $intent->payerAddressMissing);

        $this->requirePayerDetails($intent);

        $transactionId = $intent->attemptReference;

        if ($transactionId === null || ! preg_match('/^\d{10,}$/', $transactionId)) {
            throw GatewayUnavailable::forGateway($this->name(), 'no transaction identifier was prepared for this attempt');
        }

        // The decimal text goes into the JSON as a number exactly as written —
        // never through a float.
        $amountToken = '@@'.Str::random(12).'@@';

        $payload = [
            'merchantId' => $this->credentials->merchantId(),
            'storeId' => $this->credentials->storeId(),
            'CustomerOrderId' => $intent->reference,
            'merchantTransactionId' => $transactionId,
            'transactionTypeId' => self::WEB_TRANSACTION_TYPE,
            'financialEntityId' => 0,
            'transitionStatusId' => 0,
            'totalAmount' => $amountToken,
            'ipAddress' => $intent->ipAddress,
            'version' => '1',
            'successUrl' => $intent->successUrl,
            'failUrl' => $intent->failUrl,
            'cancelUrl' => $intent->cancelUrl,
            'customerName' => $intent->customerName,
            'customerEmail' => $intent->customerEmail,
            'CustomerAddress' => $address->line,
            'CustomerCity' => $address->city,
            'CustomerState' => $address->state,
            'CustomerPostcode' => $address->postcode,
            'CustomerCountry' => $address->country,
            'CustomerPhone' => $this->localPhone((string) $intent->customerMobile),
            'ValueA' => '',
            'ValueB' => '',
            'ValueC' => '',
            'ValueD' => '',
            'ShippingMethod' => 'NO',
            'NoOfItem' => '1',
            'ProductName' => $intent->description,
            'ProductProfile' => 'general',
            'ProductCategory' => 'service',
        ];

        $body = str_replace(
            '"'.$amountToken.'"',
            $intent->amount->toDecimal(),
            (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );

        $response = $this->send('POST', self::INITIALIZE_PATH, $transactionId, $intent->reference, $body);

        /** @var array<string, mixed> $data */
        $data = $this->lowercaseKeys($response->json() ?? []);

        $errorMessage = $this->text($data['errormessage'] ?? null);
        $errorCode = $this->text($data['errorcode'] ?? null);
        $sessionId = $this->text($data['transactionid'] ?? null);
        $redirectUrl = $this->text($data['redirecturl'] ?? null);

        if ($response->failed() || $errorMessage !== null || $errorCode !== null) {
            throw GatewayUnavailable::forGateway(
                $this->name(),
                $this->failureText($response->status(), $errorCode, $errorMessage),
            );
        }

        if ($sessionId === null || $redirectUrl === null || ! $this->isEpsHostedUrl($redirectUrl)) {
            throw GatewayUnavailable::malformedResponse($this->name());
        }

        $this->sessions->handle($intent->reference, $sessionId);

        return new GatewayRedirect(url: $redirectUrl, gatewayReference: $transactionId);
    }

    /**
     * Read the return request.
     *
     * Deliberately does **not** conclude the payment succeeded, whatever the
     * request says — a user can edit anything in that redirect. It reports
     * pending on an apparent success so the caller is forced through
     * verify().
     */
    public function handleCallback(Request $request): GatewayResult
    {
        $query = $this->lowercaseKeys($request->all());

        if (isset($query['data']) && ! isset($query['merchanttransactionid'])) {
            return GatewayResult::failed(
                reference: null,
                error: 'EPS IPN payloads are encrypted and the guide does not say how to decrypt them.',
                errorCode: 'ipn_unsupported',
            );
        }

        $reference = $this->text($query['merchanttransactionid'] ?? null);
        $status = mb_strtoupper((string) ($this->text($query['status'] ?? null) ?? ''));

        if (in_array($status, ['SUCCESS', 'COMPLETED'], true)) {
            return GatewayResult::pending(
                reference: (string) $reference,
                gatewayReference: $reference,
            );
        }

        if (in_array($status, ['CANCEL', 'CANCELLED', 'CANCELED'], true)) {
            return GatewayResult::cancelled($reference);
        }

        return GatewayResult::failed(
            reference: $reference,
            error: $status !== '' ? "EPS reported this transaction as {$status}." : 'EPS did not report a recognisable status.',
            errorCode: $status !== '' ? $status : null,
            gatewayReference: $reference,
        );
    }

    /**
     * Fails closed: the guide documents no IPN signature.
     */
    public function verifyWebhookSignature(Request $request): bool
    {
        return false;
    }

    /**
     * Ask EPS directly what happened, addressed by our merchantTransactionId.
     */
    public function verify(string $gatewayReference): GatewayResult
    {
        return $this->interpret($gatewayReference);
    }

    /**
     * The same single lookup the guide offers.
     */
    public function status(string $reference): GatewayResult
    {
        return $this->interpret($reference);
    }

    protected function interpret(string $merchantTransactionId): GatewayResult
    {
        $this->requireConfigured();

        $response = $this->send(
            'GET',
            self::STATUS_PATH,
            $merchantTransactionId,
            $merchantTransactionId,
            query: ['merchantTransactionId' => $merchantTransactionId],
        );

        /** @var array<string, mixed> $body */
        $body = $this->lowercaseKeys($response->json() ?? []);

        $errorCode = $this->text($body['errorcode'] ?? null);
        $errorMessage = $this->text($body['errormessage'] ?? null);

        if ($response->failed()) {
            throw GatewayUnavailable::forGateway(
                $this->name(),
                $this->failureText($response->status(), $errorCode, $errorMessage),
            );
        }

        $status = $this->text($body['status'] ?? null);

        if ($status === null) {
            throw GatewayUnavailable::malformedResponse($this->name());
        }

        // The answer must be about the transaction that was asked about.
        $echoed = $this->text($body['merchanttransactionid'] ?? null);

        if ($echoed !== null && $echoed !== $merchantTransactionId) {
            throw GatewayUnavailable::malformedResponse($this->name());
        }

        $reportedStatus = $status;
        $status = mb_strtoupper($status);
        $epsTransactionId = $this->text($body['epstransactionid'] ?? null);

        // Only what is needed to explain the outcome. The payer's name, email,
        // address and phone are in the response and are deliberately dropped.
        $raw = array_filter([
            'MerchantTransactionId' => $merchantTransactionId,
            'EpsTransactionId' => $epsTransactionId,
            'Status' => $reportedStatus,
            'TotalAmount' => $this->text($body['totalamount'] ?? null),
            'TransactionDate' => $this->text($body['transactiondate'] ?? null),
            'FinancialEntity' => $this->text($body['financialentity'] ?? null),
            'ErrorCode' => $errorCode,
            'ErrorMessage' => $errorMessage,
        ], fn (mixed $value) => $value !== null);

        if ($status === 'SUCCESS') {
            $amount = $this->amountFrom($body);

            // A success this driver cannot price is never guessed at.
            if ($amount === null) {
                throw GatewayUnavailable::malformedResponse($this->name());
            }

            return GatewayResult::paid(
                reference: $merchantTransactionId,
                gatewayReference: $merchantTransactionId,
                amount: $amount,
                raw: $raw,
                settlementReference: $epsTransactionId,
            );
        }

        if (in_array($status, ['PENDING', 'PROCESSING', 'INITIATED'], true)) {
            return GatewayResult::pending($merchantTransactionId, $merchantTransactionId, $raw);
        }

        if (in_array($status, ['CANCEL', 'CANCELLED', 'CANCELED'], true)) {
            return GatewayResult::cancelled($merchantTransactionId, $raw);
        }

        return GatewayResult::failed(
            reference: $merchantTransactionId,
            error: $errorMessage ?? "EPS reported this transaction as {$status}.",
            errorCode: $errorCode ?? $status,
            raw: $raw,
        );
    }

    /**
     * The paid amount, as exact decimal text in taka.
     *
     * @param  array<string, mixed>  $body  keys already lower-cased
     */
    protected function amountFrom(array $body): ?Money
    {
        $raw = $body['totalamount'] ?? null;

        if (! is_string($raw) && ! is_int($raw)) {
            return null;
        }

        $raw = trim((string) $raw);

        if (! preg_match('/^\d+(\.\d{1,2})?$/', $raw)) {
            return null;
        }

        try {
            return Money::fromDecimal($raw, Currency::BDT);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * One authenticated call, with the token and hash made here.
     *
     * A token EPS rejects is dropped and fetched again once. Returns the raw
     * response for the caller to read; only a transport failure throws.
     *
     * @param  string  $hashed  what `x-hash` signs
     * @param  string  $reference  what to correlate the log line with
     * @param  array<string, string>  $query
     */
    protected function send(
        string $method,
        string $path,
        string $hashed,
        string $reference,
        ?string $body = null,
        array $query = [],
    ): Response {
        $token = $this->token();

        $response = $this->dispatch($method, $path, $hashed, $reference, $body, $query, $token);

        if ($response->status() === 401) {
            $response = $this->dispatch(
                $method, $path, $hashed, $reference, $body, $query,
                $this->token(forceRefresh: true, rejected: $token),
            );
        }

        return $response;
    }

    /**
     * @param  array<string, string>  $query
     */
    protected function dispatch(
        string $method,
        string $path,
        string $hashed,
        string $reference,
        ?string $body,
        array $query,
        string $token,
    ): Response {
        $request = $this->http
            ->withHeaders(['x-hash' => $this->sign($hashed), 'Authorization' => "Bearer {$token}"])
            ->acceptJson()
            ->timeout(20);

        try {
            $response = $method === 'GET'
                ? $request->get($this->url($path), $query)
                : $request->withBody((string) $body, 'application/json')->post($this->url($path));
        } catch (ConnectionException) {
            throw GatewayUnavailable::forGateway($this->name(), 'connection failed');
        }

        $this->logCall($method, $path, $reference, $response);

        return $response;
    }

    /**
     * A bearer token — from the cache while it is good, else from GetToken.
     *
     * Refreshes are serialised, so a burst of requests asks EPS once. When a
     * token has just been rejected, anyone who finds a *different* cached
     * token under the lock uses that instead of asking again.
     */
    protected function token(bool $forceRefresh = false, ?string $rejected = null): string
    {
        $key = $this->tokenCacheKey();

        if (! $forceRefresh && ($cached = $this->cachedToken($key)) !== null) {
            return $cached;
        }

        return $this->lock->run(
            key: $key.':refresh',
            callback: function () use ($key, $forceRefresh, $rejected): string {
                $cached = $this->cachedToken($key);

                if ($cached !== null && ($rejected !== null ? $cached !== $rejected : ! $forceRefresh)) {
                    return $cached;
                }

                $this->cache->forget($key);

                return $this->requestToken($key);
            },
            ttlSeconds: 30,
            waitSeconds: 10,
        );
    }

    protected function requestToken(string $key): string
    {
        $username = $this->credentials->username();

        try {
            $response = $this->http
                ->withHeaders(['x-hash' => $this->sign($username)])
                ->acceptJson()
                ->asJson()
                ->timeout(20)
                ->post($this->url(self::GET_TOKEN_PATH), [
                    'userName' => $username,
                    'password' => $this->credentials->password(),
                ]);
        } catch (ConnectionException) {
            throw GatewayUnavailable::forGateway($this->name(), 'connection failed');
        }

        $this->logCall('POST', self::GET_TOKEN_PATH, null, $response);

        /** @var array<string, mixed> $body */
        $body = $this->lowercaseKeys($response->json() ?? []);
        $token = $body['token'] ?? null;

        if ($response->failed() || ! is_string($token) || $token === '') {
            throw GatewayUnavailable::forGateway(
                $this->name(),
                $response->failed() || $this->text($body['errormessage'] ?? null) !== null
                    ? $this->failureText(
                        $response->status(),
                        $this->text($body['errorcode'] ?? null),
                        $this->text($body['errormessage'] ?? null),
                    )
                    : 'could not obtain an access token',
            );
        }

        $ttl = $this->tokenLifetime($token, $this->text($body['expiredate'] ?? null)) - self::TOKEN_SKEW_SECONDS;

        if ($ttl > 0) {
            $this->cache->put($key, $this->encrypter->encryptString($token), $ttl);
        }

        return $token;
    }

    protected function cachedToken(string $key): ?string
    {
        $stored = $this->cache->get($key);

        if (! is_string($stored)) {
            return null;
        }

        try {
            return $this->encrypter->decryptString($stored);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Seconds the token is good for.
     *
     * The JWT's own `exp` is preferred — it is unambiguous. The guide's
     * `expireDate` carries no zone (its sample is Bangladesh time), so it is
     * read as Asia/Dhaka, and only when `exp` is absent.
     */
    protected function tokenLifetime(string $token, ?string $expireDate): int
    {
        $segments = explode('.', $token);

        if (count($segments) === 3) {
            $payload = json_decode((string) base64_decode(strtr($segments[1], '-_', '+/'), true), true);

            if (is_array($payload) && is_numeric($payload['exp'] ?? null)) {
                return (int) $payload['exp'] - time();
            }
        }

        if ($expireDate !== null) {
            try {
                return (int) Carbon::parse($expireDate, 'Asia/Dhaka')->timestamp - time();
            } catch (Throwable) {
                // Fall through.
            }
        }

        return self::TOKEN_FALLBACK_SECONDS;
    }

    /**
     * Per provider, mode, endpoint and merchant — and never the username itself.
     */
    protected function tokenCacheKey(): string
    {
        return 'eps:token:'.hash('sha256', implode('|', [
            $this->credentials->mode(),
            $this->credentials->baseUrl(),
            $this->credentials->username(),
        ]));
    }

    /**
     * `x-hash`: base64(hmac_sha512($data, hashKey)), raw digest.
     */
    protected function sign(string $data): string
    {
        return base64_encode(hash_hmac('sha512', $data, $this->credentials->hashKey(), true));
    }

    /**
     * The one place a path meets the base URL.
     */
    protected function url(string $path): string
    {
        return $this->credentials->baseUrl().$path;
    }

    /**
     * Whether a redirect address is an https page EPS itself hosts.
     */
    protected function isEpsHostedUrl(string $url): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $host = mb_strtolower((string) ($parts['host'] ?? ''));

        return $host === 'eps.com.bd' || str_ends_with($host, '.eps.com.bd');
    }

    /**
     * The guide marks name, email and phone mandatory.
     *
     * @throws GatewayUnavailable
     */
    protected function requirePayerDetails(PaymentIntent $intent): void
    {
        $missing = array_keys(array_filter([
            'name' => trim($intent->customerName) === '',
            'email address' => trim($intent->customerEmail) === '',
            'mobile number' => trim((string) $intent->customerMobile) === '',
            'IP address' => ! filter_var($intent->ipAddress, FILTER_VALIDATE_IP),
        ]));

        if ($missing !== []) {
            throw GatewayUnavailable::forGateway(
                $this->name(),
                'the payer\'s '.implode(', ', $missing).' is required before this payment can start',
            );
        }
    }

    /**
     * `+8801712345678` / `8801712345678` → `01712345678`, the form EPS's own
     * sample uses. Anything else is passed through as stored.
     */
    protected function localPhone(string $mobile): string
    {
        $digits = preg_replace('/[\s\-]/', '', $mobile) ?? $mobile;

        return preg_match('/^\+?880(1\d{9})$/', $digits, $matches) ? '0'.$matches[1] : $digits;
    }

    /**
     * Log one call, and nothing sensitive about it: no credential, hash,
     * token, header, payer detail or payload — only where it went, how it
     * ended, and EPS's own error.
     */
    protected function logCall(string $method, string $path, ?string $reference, Response $response): void
    {
        $body = $this->lowercaseKeys($response->json() ?? []);

        $this->log->channel('payment')->info('EPS call', [
            'correlation' => Str::lower(Str::random(12)),
            'payment' => $reference,
            'mode' => $this->credentials->mode(),
            'host' => parse_url($this->credentials->baseUrl(), PHP_URL_HOST),
            'path' => $path,
            'method' => $method,
            'status' => $response->status(),
            'error_code' => $this->text($body['errorcode'] ?? null),
            'error_message' => $this->text($body['errormessage'] ?? null),
        ]);
    }

    protected function failureText(int $status, ?string $code, ?string $message): string
    {
        $detail = $message ?? $code;

        return 'HTTP '.$status.($detail !== null ? ': '.mb_substr($detail, 0, 200) : '');
    }

    /**
     * A non-empty string, or null — EPS sends `""` and `null` for "nothing".
     */
    protected function text(mixed $value): ?string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * The guide's responses are PascalCase where the SDK samples were
     * camelCase; reading case-insensitively accepts both.
     *
     * @param  array<array-key, mixed>  $body
     * @return array<string, mixed>
     */
    protected function lowercaseKeys(array $body): array
    {
        $out = [];

        foreach ($body as $key => $value) {
            $out[mb_strtolower((string) $key)] = is_array($value) ? $this->lowercaseKeys($value) : $value;
        }

        return $out;
    }

    protected function credentials(): GatewayCredentials
    {
        return $this->credentials;
    }
}
