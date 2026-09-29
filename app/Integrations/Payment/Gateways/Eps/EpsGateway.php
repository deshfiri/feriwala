<?php

namespace App\Integrations\Payment\Gateways\Eps;

use App\Integrations\Payment\Data\GatewayCapability;
use App\Integrations\Payment\Data\GatewayRedirect;
use App\Integrations\Payment\Data\GatewayResult;
use App\Integrations\Payment\Data\PaymentIntent;
use App\Integrations\Payment\Exceptions\GatewayUnavailable;
use App\Integrations\Payment\Gateways\Gateway;
use App\Integrations\Payment\Gateways\GatewayCredentials;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Http\Request;
use Illuminate\Log\LogManager;
use Throwable;

/**
 * EPS — Easy Payment System (§26, D7).
 *
 * Sources, read and pinned 2026-09-29:
 *   - https://www.eps.com.bd/ (links to the API documentation page and the
 *     official GitHub organisation)
 *   - https://www.eps.com.bd/eps-gateway (no endpoint paths, schemas or
 *     signing rules beyond what the linked SDKs contain)
 *   - https://www.eps.com.bd/ipn (the IPN's own page — AES-256-CBC, PKCS7,
 *     a "Data": "IV:CipherText" envelope, and a decrypted field list, but
 *     never states what the "Secret Key" it names actually is)
 *   - https://github.com/EPS-PG (the official GitHub organisation) —
 *     specifically EPS_Laravel at commit `2ae54af21eed1eb8cc8f05505379cff60dfd30b3`
 *     (`app/EPS/EPSPayment.php`, `config/epsPayment.php`,
 *     `app/Http/Controllers/EPSExampleController.php`) and EPS_PHP at commit
 *     `c1ace43cc8fb07f686eefe340363690074927603` (`Sandbox&ProductionPhP.php`,
 *     which is also where the sandbox and live base URLs actually live —
 *     neither repo's README states them)
 *
 * **What those sources confirm**, independently verified against the live
 * sandbox using EPS_PHP's own published demo credentials (`Epsdemo@gmail.com`
 * / merchant `29e86e70-0ac6-45eb-ba04-9fcb0aaed12a`) — a real `GetToken` call
 * against {@see self::SANDBOX_HOST} returned a real JWT in exactly the shape
 * read from the source:
 *
 *   - Authentication is `x-hash: base64(hmac_sha512($signedString, $hashKey))`
 *     plus, after the first call, a bearer token from `POST /v1/Auth/GetToken`.
 *   - `GetToken`'s signed string is the username; `InitializeEPS` and
 *     `CheckMerchantTransactionStatus`'s signed string is the merchant's own
 *     transaction id.
 *   - `InitializeEPS`'s request body, and that it returns `RedirectURL` on
 *     success (`ErrorMessage` on failure, unconfirmed field name for the
 *     success shape's own field list beyond `RedirectURL`).
 *   - `CheckMerchantTransactionStatus` takes only the merchant's own
 *     transaction id — EPS never gives a way to ask by *its own* transaction
 *     identifier, so `verify()` and `status()` are, for this provider, the
 *     same question asked the same way.
 *
 * **What is not confirmed, from any of the above, and not pursued further**:
 * the exact field names `CheckMerchantTransactionStatus` returns on a real
 * paid transaction. EPS_PHP's own published example — the only sample that
 * touches this response at all — tries four different possible field paths
 * (`transactionStatus`, `status`, `data.transactionStatus`, `data.status`)
 * and falls back to "UNKNOWN", which is the vendor's own code admitting it
 * does not reliably know its own response shape either. This driver reads
 * defensively across those same paths and refuses (never fabricates a paid
 * result) when none of them yield a recognisable status or amount — see
 * {@see interpret()}. Confirming this exactly needs either EPS's own
 * integration guide (integration@eps.com.bd) or a completed sandbox
 * transaction to inspect, and this session's sandbox access was for
 * confirming the *shape* of an authentication call, not for driving a test
 * transaction through to a paid state.
 *
 * The IPN channel ({@see verifyWebhookSignature()}) is separately
 * unconfirmed: nothing above states what the AES-256-CBC "Secret Key" is —
 * the same `hash_key` used for signing, a distinct value, or something
 * issued out of band — so it is not implemented, and `handleCallback()`
 * recognises an encrypted IPN body only well enough to refuse it rather than
 * silently misreading it. This does not weaken settlement: §26.4's own rule
 * holds regardless of channel — nothing releases value except a direct
 * `verify()`/`status()` call, which the browser-return path already reaches
 * with a real, unencrypted merchant transaction id every time a payer
 * returns.
 */
class EpsGateway extends Gateway
{
    public const SANDBOX_HOST = 'https://sandboxpgapi.eps.com.bd';

    public const LIVE_HOST = 'https://pgapi.eps.com.bd';

    protected const GET_TOKEN_PATH = '/v1/Auth/GetToken';

    protected const INITIALIZE_PATH = '/v1/EPSEngine/InitializeEPS';

    protected const STATUS_PATH = '/v1/EPSEngine/CheckMerchantTransactionStatus';

    public function __construct(
        protected HttpClient $http,
        protected LogManager $log,
        protected EpsCredentials $credentials,
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

    public function initiate(PaymentIntent $intent): GatewayRedirect
    {
        $this->requireConfigured();

        $token = $this->token();

        $response = $this->http
            ->withHeaders([
                'x-hash' => $this->sign($intent->reference),
                'Authorization' => "Bearer {$token}",
            ])
            ->timeout(20)
            ->post($this->host().self::INITIALIZE_PATH, [
                'deviceTypeId' => $this->credentials->deviceTypeId(),
                'merchantId' => $this->credentials->merchantId(),
                'storeId' => $this->credentials->storeId(),
                'transactionTypeId' => 1,
                'financialEntityId' => 0,
                'version' => '1',
                'transactionDate' => now()->toIso8601String(),
                'transitionStatusId' => 0,
                'valueD' => '',

                // EPS's own reference for this transaction and ours are the
                // same string — the only identifier the browser return and
                // CheckMerchantTransactionStatus both key on.
                'merchantTransactionId' => $intent->reference,
                'CustomerOrderId' => $intent->reference,

                // EPS expects a decimal string, not minor units. This is the
                // one place the conversion happens.
                'totalAmount' => $intent->amount->toDecimal(),

                'successUrl' => $intent->successUrl,
                'failUrl' => $intent->failUrl,
                'cancelUrl' => $intent->cancelUrl,

                'customerName' => $intent->customerName,
                'customerEmail' => $intent->customerEmail,
                'customerPhone' => $intent->customerMobile ?? '',

                'productName' => $intent->description,
                'productProfile' => 'general',
                'productCategory' => 'service',
            ]);

        if ($response->failed()) {
            throw GatewayUnavailable::forGateway($this->name(), 'HTTP '.$response->status());
        }

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        if (empty($body['RedirectURL'])) {
            $this->log->channel('payment')->warning('EPS refused to start a session', [
                'reference' => $intent->reference,
                'reason' => $body['ErrorMessage'] ?? $body['errorMessage'] ?? null,
            ]);

            throw GatewayUnavailable::forGateway(
                $this->name(),
                (string) ($body['ErrorMessage'] ?? $body['errorMessage'] ?? 'session could not be created'),
            );
        }

        return new GatewayRedirect(
            url: (string) $body['RedirectURL'],
            gatewayReference: $intent->reference,
        );
    }

    /**
     * Read the return request.
     *
     * Deliberately does **not** conclude the payment succeeded, whatever the
     * request says — a user can edit anything in that redirect. It reports
     * pending on an apparent success so the caller is forced through
     * verify().
     *
     * Also the entry point for a genuine IPN post ({@see PaymentWebhookController}
     * routes every provider's notification through this same method) — an
     * encrypted EPS IPN body carries none of the plain fields below, so it
     * falls through to the `ipn_unsupported` refusal rather than being
     * misread as an empty or malformed redirect.
     */
    public function handleCallback(Request $request): GatewayResult
    {
        if ($request->has('Data') && ! $request->has('MerchantTransactionId') && ! $request->has('merchantTransactionId')) {
            return GatewayResult::failed(
                reference: null,
                error: 'EPS IPN payloads are AES-256-CBC encrypted and this driver cannot decrypt them '
                    .'(the "Secret Key" EPS documents is not identified anywhere in their public materials).',
                errorCode: 'ipn_unsupported',
                raw: $request->all(),
            );
        }

        $reference = $request->input('MerchantTransactionId', $request->input('merchantTransactionId'));
        $reference = is_string($reference) && $reference !== '' ? $reference : null;

        $status = mb_strtoupper((string) $request->input('Status', $request->input('status', '')));

        if (in_array($status, ['SUCCESS', 'COMPLETED'], true)) {
            return GatewayResult::pending(
                reference: (string) $reference,
                gatewayReference: $reference,
                raw: $request->all(),
            );
        }

        if (in_array($status, ['CANCEL', 'CANCELLED', 'CANCELED'], true)) {
            return GatewayResult::cancelled($reference, $request->all());
        }

        return GatewayResult::failed(
            reference: $reference,
            error: $status !== '' ? "EPS reported this transaction as {$status}." : 'EPS did not report a recognisable status.',
            errorCode: $status !== '' ? $status : null,
            raw: $request->all(),
            gatewayReference: $reference,
        );
    }

    /**
     * Fails closed. See the class docblock: the IPN "Secret Key" EPS
     * documents is not identified anywhere in their public materials, so
     * nothing here can tell a genuine notification from a forged one.
     */
    public function verifyWebhookSignature(Request $request): bool
    {
        return false;
    }

    /**
     * Ask EPS directly what happened to a transaction.
     *
     * EPS exposes exactly one lookup, addressed by the merchant's own
     * transaction id — there is no separate identifier to verify *its* side
     * by, so `verify()` and {@see status()} ask the same question.
     */
    public function verify(string $gatewayReference): GatewayResult
    {
        return $this->interpret($gatewayReference);
    }

    /**
     * Ask what became of one of our own transaction ids (§28.1).
     *
     * The same call as {@see verify()} — EPS has no other. Kept as its own
     * method because reconciliation calls it without a `gatewayReference`
     * from anywhere else, which for EPS is always this same string anyway.
     */
    public function status(string $reference): GatewayResult
    {
        return $this->interpret($reference);
    }

    protected function interpret(string $reference): GatewayResult
    {
        $this->requireConfigured();

        $token = $this->token();

        try {
            $response = $this->http
                ->withHeaders([
                    'x-hash' => $this->sign($reference),
                    'Authorization' => "Bearer {$token}",
                ])
                ->timeout(20)
                ->get($this->host().self::STATUS_PATH, [
                    'merchantTransactionId' => $reference,
                ]);
        } catch (ConnectionException) {
            throw GatewayUnavailable::forGateway($this->name(), 'verification connection failed');
        }

        if ($response->failed()) {
            throw GatewayUnavailable::forGateway($this->name(), 'HTTP '.$response->status());
        }

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        /*
         * Read across every shape EPS_PHP's own published example tries
         * ("transactionStatus", "status", each nested under "data") rather
         * than picking one — that example is the only place any of the
         * vendor's own sources touch a real response, and it does not
         * commit to a single shape either.
         */
        $status = $body['transactionStatus']
            ?? $body['status']
            ?? ($body['data']['transactionStatus'] ?? null)
            ?? ($body['data']['status'] ?? null);

        $status = is_string($status) ? mb_strtoupper($status) : null;

        if ($status === null) {
            throw GatewayUnavailable::malformedResponse($this->name());
        }

        if (in_array($status, ['SUCCESS', 'COMPLETED'], true)) {
            $amount = $this->amountFrom($body);

            if ($amount === null) {
                // A confirmed success this driver cannot price is not
                // evidence of nothing — it is evidence this response shape
                // was never confirmed. Refusing here is the whole point of
                // never guessing at a field that decides how much money
                // moves.
                throw GatewayUnavailable::malformedResponse($this->name());
            }

            return GatewayResult::paid(
                reference: $reference,
                gatewayReference: $reference,
                amount: $amount,
                raw: $body,
            );
        }

        if (in_array($status, ['PENDING', 'PROCESSING'], true)) {
            return GatewayResult::pending($reference, $reference, $body);
        }

        if (in_array($status, ['CANCEL', 'CANCELLED', 'CANCELED'], true)) {
            return GatewayResult::cancelled($reference, $body);
        }

        return GatewayResult::failed(
            reference: $reference,
            error: "EPS reported this transaction as {$status}.",
            errorCode: $status,
            raw: $body,
        );
    }

    /**
     * The paid amount, read across the same uncertainty as the status field.
     *
     * @param  array<string, mixed>  $body
     */
    protected function amountFrom(array $body): ?Money
    {
        $raw = $body['totalAmount']
            ?? $body['amount']
            ?? ($body['data']['totalAmount'] ?? null)
            ?? ($body['data']['amount'] ?? null);

        if (! is_numeric($raw)) {
            return null;
        }

        try {
            return Money::fromDecimal((string) $raw, Currency::BDT);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * `x-hash`: base64(hmac_sha512($data, hash_key)) — confirmed against the
     * live sandbox (see the class docblock).
     */
    protected function sign(string $data): string
    {
        return base64_encode(hash_hmac('sha512', $data, $this->credentials->hashKey(), true));
    }

    /**
     * A bearer token from `POST /v1/Auth/GetToken`, signed with the
     * username.
     */
    protected function token(): string
    {
        $response = $this->http
            ->withHeaders(['x-hash' => $this->sign($this->credentials->username())])
            ->timeout(20)
            ->post($this->host().self::GET_TOKEN_PATH, [
                'userName' => $this->credentials->username(),
                'password' => $this->credentials->password(),
            ]);

        if ($response->failed()) {
            throw GatewayUnavailable::forGateway($this->name(), 'HTTP '.$response->status());
        }

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        $token = $body['token'] ?? null;

        if (! is_string($token) || $token === '') {
            throw GatewayUnavailable::forGateway(
                $this->name(),
                (string) ($body['errorMessage'] ?? 'could not obtain an access token'),
            );
        }

        return $token;
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
