<?php

namespace App\Integrations\Payment\Gateways\SslCommerz;

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
use Illuminate\Log\LogManager;
use Throwable;

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
class SslCommerzGateway extends Gateway
{
    public const SANDBOX_HOST = 'https://sandbox.sslcommerz.com';

    public const LIVE_HOST = 'https://securepay.sslcommerz.com';

    public function __construct(
        protected HttpClient $http,
        protected LogManager $log,
        protected SslCommerzCredentials $credentials,
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

            // `refund_amount` is a parameter rather than a flag, so a refund
            // for less than the original is the same call as a refund for all
            // of it.
            GatewayCapability::RefundFull,
            GatewayCapability::RefundPartial,
            GatewayCapability::RefundStatus,
        ];
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

        /*
         * The transaction id travels with the result whatever the request
         * claims happened. Identity and outcome are different things: the
         * claimed status is unverified, but `val_id` is what we need in order
         * to go and ask, and dropping it because the browser said "failed"
         * would leave a real payment unverifiable.
         */
        $gatewayReference = $request->input('val_id');
        $gatewayReference = is_string($gatewayReference) && $gatewayReference !== ''
            ? $gatewayReference
            : null;

        if ($status === 'VALID' || $status === 'VALIDATED') {
            return GatewayResult::pending(
                reference: (string) $reference,
                gatewayReference: $gatewayReference,
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
            gatewayReference: $gatewayReference,
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

                // The banking side's own identifier, and the only thing a
                // refund can be addressed to. Kept now, because asking for it
                // again later is an extra call that can fail at the worst
                // possible moment.
                settlementReference: $this->bankTransactionId($body),

                fee: $this->feeFrom($body),
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

    /**
     * Ask what became of one of our own transaction ids (§28.1).
     *
     * The documented `tran_id` query. It answers with `no_of_trans_found` and an
     * `element` array rather than a single record, because SSLCommerz allows a
     * transaction id to be attempted more than once — a payer who failed and
     * tried again produces two.
     *
     * The **successful** attempt is the one that matters, and it is looked for
     * explicitly rather than taking the first or last element: ordering is not
     * documented, and "the last one failed" is a different fact from "this was
     * never paid" when an earlier attempt went through.
     */
    public function status(string $reference): GatewayResult
    {
        $body = $this->query([
            'tran_id' => $reference,
            'store_id' => $this->credentials->storeId(),
            'store_passwd' => $this->credentials->storePassword(),
            'format' => 'json',
        ]);

        $elements = $body['element'] ?? null;

        /** @var array<int, array<string, mixed>> $records */
        $records = is_array($elements)
            ? array_values(array_filter($elements, 'is_array'))
            : [];

        if ($records === []) {
            return GatewayResult::failed(
                reference: $reference,
                error: 'The gateway has no record of this transaction.',
                errorCode: 'not_found',
                raw: $body,
            );
        }

        foreach ($records as $record) {
            $status = (string) ($record['status'] ?? '');

            if ($status !== 'VALID' && $status !== 'VALIDATED') {
                continue;
            }

            if (! isset($record['currency_amount'], $record['currency_type'])) {
                throw GatewayUnavailable::malformedResponse($this->name());
            }

            return GatewayResult::paid(
                reference: $reference,
                gatewayReference: (string) ($record['val_id'] ?? ''),
                amount: Money::fromDecimal(
                    (string) $record['currency_amount'],
                    Currency::from((string) $record['currency_type']),
                ),
                raw: $record,
                settlementReference: $this->bankTransactionId($record),
            );
        }

        // Nothing succeeded. The most recent attempt is what the payer saw, so
        // its status is what this reports.
        $latest = $records[array_key_last($records)];
        $status = (string) ($latest['status'] ?? '');

        if ($status === 'PENDING') {
            return GatewayResult::pending($reference, null, $latest);
        }

        return GatewayResult::failed(
            reference: $reference,
            error: 'The gateway reported this transaction as '.($status !== '' ? $status : 'unknown').'.',
            errorCode: $status !== '' ? $status : null,
            raw: $latest,
        );
    }

    /**
     * Return money through SSLCommerz (§26.3).
     *
     * Addressed to `bank_tran_id` — the banking side's identifier, not the
     * validation id — because that is what the documented refund call takes.
     *
     * `refund_trans_id` carries our own idempotency key. SSLCommerz describes it
     * as a unique transaction id for the refund, so sending the same one twice
     * is how a retry is recognised as the same instruction rather than a second
     * one.
     *
     * **`success` is not settled.** The provider's own vocabulary separates
     * `success` from `processing`, and only a later refund query returns
     * `refunded`. Treating acceptance as completion would reverse money in our
     * ledger that has not yet left theirs, so an accepted refund comes back
     * pending and stays pending until SSLCommerz says otherwise.
     */
    public function refund(RefundIntent $intent): RefundResult
    {
        $this->requireCapability(
            $intent->isFull() ? GatewayCapability::RefundFull : GatewayCapability::RefundPartial,
        );

        $body = $this->query([
            'bank_tran_id' => $intent->gatewayReference,
            'refund_trans_id' => $intent->idempotencyKey,
            'store_id' => $this->credentials->storeId(),
            'store_passwd' => $this->credentials->storePassword(),
            'refund_amount' => $intent->amount->toDecimal(),
            'refund_remarks' => $intent->reason,
            'refe_id' => $intent->reference,
            'format' => 'json',
        ]);

        $status = mb_strtolower((string) ($body['status'] ?? ''));

        $reference = isset($body['refund_ref_id']) && $body['refund_ref_id'] !== ''
            ? (string) $body['refund_ref_id']
            : null;

        if ($status === 'success' || $status === 'processing') {
            /*
             * Accepted without a reference to ask about later is not something
             * we can carry: the refund query takes `refund_ref_id` and nothing
             * else, so a missing one means the instruction can never be
             * followed up. Raised rather than recorded, so the caller leaves
             * the refund unmade instead of holding an untrackable one.
             */
            if ($reference === null) {
                throw GatewayUnavailable::malformedResponse($this->name());
            }

            return RefundResult::pending(
                gatewayRefundReference: $reference,
                amount: $intent->amount,
                raw: $body,
            );
        }

        return RefundResult::failed(
            error: (string) ($body['errorReason'] ?? 'The gateway refused this refund.'),
            errorCode: $status !== '' ? $status : (string) ($body['APIConnect'] ?? 'unknown'),
            raw: $body,
            gatewayRefundReference: $reference,
        );
    }

    /**
     * What became of a refund already submitted.
     *
     * `refunded` is the only status that means the money has gone back;
     * `processing` is still in flight and `cancelled` means it never will.
     */
    public function refundStatus(string $gatewayRefundReference): RefundResult
    {
        $body = $this->query([
            'refund_ref_id' => $gatewayRefundReference,
            'store_id' => $this->credentials->storeId(),
            'store_passwd' => $this->credentials->storePassword(),
            'format' => 'json',
        ]);

        $status = mb_strtolower((string) ($body['status'] ?? ''));

        if ($status === 'refunded') {
            /*
             * The refund query does not echo an amount back, so there is
             * nothing here to contradict what was sent. The caller compares
             * against its own record — the amount it asked for, which is the
             * amount it will reverse.
             */
            return RefundResult::succeeded(
                gatewayRefundReference: $gatewayRefundReference,
                amount: null,
                raw: $body,
            );
        }

        if ($status === 'processing') {
            return RefundResult::pending(
                gatewayRefundReference: $gatewayRefundReference,
                raw: $body,
            );
        }

        return RefundResult::failed(
            error: (string) ($body['errorReason'] ?? 'The gateway reported this refund as '.($status !== '' ? $status : 'unknown').'.'),
            errorCode: $status !== '' ? $status : null,
            raw: $body,
            gatewayRefundReference: $gatewayRefundReference,
        );
    }

    /**
     * One GET against the merchant transaction API, with its connection checked.
     *
     * `APIConnect` is SSLCommerz's own answer to "did this call even reach a
     * working merchant account", and it is separate from what the call asked
     * about. Anything but `DONE` means the question was never answered — bad
     * credentials, an inactive account, a malformed request — so it throws
     * rather than being read as a refusal, because "your refund failed" and "we
     * could not ask" are not the same thing to say about somebody's money.
     *
     * @param  array<string, string>  $query
     * @return array<string, mixed>
     */
    protected function query(array $query): array
    {
        $response = $this->http
            ->timeout(20)
            ->get($this->host().'/validator/api/merchantTransIDvalidationAPI.php', $query);

        if ($response->failed()) {
            throw GatewayUnavailable::forGateway($this->name(), 'HTTP '.$response->status());
        }

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        $connect = (string) ($body['APIConnect'] ?? '');

        if ($connect !== 'DONE') {
            $this->log->channel('payment')->warning('SSLCommerz refused an API call', [
                // The parameter names only. `store_passwd` is in the query and
                // a log is the last place it should turn up (§42).
                'parameters' => array_keys($query),
                'api_connect' => $connect,
            ]);

            throw GatewayUnavailable::forGateway(
                $this->name(),
                'the merchant API answered '.($connect !== '' ? $connect : 'nothing'),
            );
        }

        return $body;
    }

    /**
     * What SSLCommerz kept, derived from what they say lands in the account.
     *
     * `store_amount` is documented as "the amount what you will get in your
     * account after bank charge", so the fee is the difference between it and
     * the amount charged. Both are in the transaction's own currency.
     *
     * Null when SSLCommerz does not report a store amount, because a zero would
     * claim they charged nothing — and a negative would mean they credited more
     * than was paid, which is not a fee and is not something to record as one.
     *
     * @param  array<string, mixed>  $body
     */
    protected function feeFrom(array $body): ?Money
    {
        if (! isset($body['store_amount'], $body['currency_amount'], $body['currency_type'])) {
            return null;
        }

        try {
            $currency = Currency::from((string) $body['currency_type']);

            $charged = Money::fromDecimal((string) $body['currency_amount'], $currency);
            $received = Money::fromDecimal((string) $body['store_amount'], $currency);
        } catch (Throwable) {
            return null;
        }

        $fee = $charged->minus($received);

        return $fee->isPositive() ? $fee : null;
    }

    /**
     * The banking-side transaction id, when the provider gave one.
     *
     * @param  array<string, mixed>  $body
     */
    protected function bankTransactionId(array $body): ?string
    {
        $id = $body['bank_tran_id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    protected function credentials(): GatewayCredentials
    {
        return $this->credentials;
    }

    /**
     * Where the gateway's API lives.
     *
     * Live is the live host and nothing else. In **sandbox** mode, and only
     * outside production, a local stub may be named instead — what a developer
     * or a browser check runs against a machine with no route to SSLCommerz.
     * Anything that is not a local address is ignored, so the override can
     * never point real money somewhere else.
     */
    protected function host(): string
    {
        if (! $this->isSandbox()) {
            return self::LIVE_HOST;
        }

        $override = config('payment.gateways.sslcommerz.sandbox_host');

        if (is_string($override) && $override !== '' && ! app()->environment('production') && self::isLocalHost($override)) {
            return rtrim($override, '/');
        }

        return self::SANDBOX_HOST;
    }

    /**
     * Whether an address is on this machine — the only place a stub may live.
     */
    protected static function isLocalHost(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            && in_array(mb_strtolower((string) ($parts['host'] ?? '')), ['127.0.0.1', 'localhost', '::1'], true);
    }
}
