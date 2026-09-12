<?php

namespace App\Integrations\Payment\Gateways\AmarPay;

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
 * aamarPay (§26, D7).
 *
 * Built against aamarPay's published API reference: a JSON initiation returning
 * a payment URL, and a Search Transaction call that is the authoritative answer.
 *
 * Both of aamarPay's lookups are addressed by **our** transaction id — their
 * `request_id` is documented as "your unique identifier for merchant
 * transactions" — so {@see verify()} and {@see status()} ask the same question
 * and are the same call. That is unusual enough to be worth saying out loud:
 * `pg_txnid` is aamarPay's own identifier and is kept as the settlement
 * reference, but it is not what you can look a transaction up by.
 *
 * **Its IPN is not verifiable here.** aamarPay says each notification is signed,
 * but does not publish the algorithm, the signed fields, or the field the
 * signature arrives in. So the signature capability is absent and
 * {@see verifyWebhookSignature()} fails closed. A notification is a nudge to go
 * and ask; the Search Transaction call decides. That costs nothing, because
 * nothing in this application releases value on a notification anyway.
 *
 * No refund API is documented, so none is declared.
 */
class AmarPayGateway extends Gateway
{
    public const SANDBOX_HOST = 'https://sandbox.aamarpay.com';

    public const LIVE_HOST = 'https://secure.aamarpay.com';

    /**
     * The one `pay_status` aamarPay publishes.
     *
     * Their documentation shows `Successful` and does not enumerate the rest.
     * So this driver acts on that and treats every other answer as *not yet
     * confirmed* rather than inventing spellings for failure and cancellation —
     * a guessed status string either never matches, or matches the wrong thing.
     *
     * Pending is the safe side of that: it releases nothing, and an abandoned
     * payment is closed by its own deadline rather than by a word this driver
     * hoped the provider would use.
     */
    public const STATUS_SUCCESSFUL = 'successful';

    public function __construct(
        protected HttpClient $http,
        protected AmarPayCredentials $credentials,
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

        $response = $this->http
            ->asJson()
            ->timeout(20)
            ->post($this->host().'/jsonpost.php', [
                'store_id' => $this->credentials->storeId(),
                'signature_key' => $this->credentials->signatureKey(),

                'tran_id' => $intent->reference,

                // A decimal string, not minor units. The one place this
                // conversion happens for aamarPay.
                'amount' => $intent->amount->toDecimal(),
                'currency' => $intent->amount->currency->value,

                'desc' => $intent->description,

                'cus_name' => $intent->customerName,
                'cus_email' => $intent->customerEmail,
                'cus_phone' => $intent->customerMobile ?? '',

                'success_url' => $intent->successUrl,
                'fail_url' => $intent->failUrl,
                'cancel_url' => $intent->cancelUrl,

                'type' => 'json',
            ]);

        if ($response->failed()) {
            throw GatewayUnavailable::forGateway($this->name(), 'HTTP '.$response->status());
        }

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        $url = $body['payment_url'] ?? null;

        if (! is_string($url) || $url === '') {
            throw GatewayUnavailable::forGateway(
                $this->name(),
                (string) ($body['result'] ?? 'no payment url was returned'),
            );
        }

        /*
         * aamarPay returns a path rather than an absolute URL. Resolved against
         * the host this driver is already pointed at, so a sandbox initiation
         * cannot produce a live checkout link.
         */
        return new GatewayRedirect(
            url: str_starts_with($url, 'http') ? $url : $this->host().'/'.ltrim($url, '/'),
            gatewayReference: null,
        );
    }

    /**
     * Read the return request.
     *
     * Reports pending on anything resembling success. The browser is not
     * evidence; `mer_txnid` is only what says which transaction to go and ask
     * about.
     */
    public function handleCallback(Request $request): GatewayResult
    {
        $reference = $request->input('mer_txnid');
        $reference = is_string($reference) && $reference !== '' ? $reference : null;

        if ($reference === null) {
            return GatewayResult::failed(
                reference: null,
                error: 'The gateway returned without naming a transaction.',
                raw: $request->all(),
            );
        }

        /*
         * Even a claimed failure comes back as pending, and deliberately.
         * aamarPay is looked up by our own reference, so nothing is lost by
         * asking — whereas believing a redirect that says "failed" would
         * abandon a payment anybody could have marked failed by editing a URL.
         *
         * Which of the three outcomes actually happened is decided by which of
         * the three return URLs the gateway used, not by a field in the body.
         */
        return GatewayResult::pending(
            reference: $reference,
            gatewayReference: $reference,
            raw: $request->all(),
        );
    }

    /**
     * aamarPay's notification signature is not published.
     *
     * Their documentation says notifications are signed but does not state the
     * algorithm, the signed fields, or where the signature arrives — and a
     * signature check written from a guess is worse than none, because it looks
     * like protection. Fails closed until aamarPay publishes it.
     */
    public function verifyWebhookSignature(Request $request): bool
    {
        return false;
    }

    /**
     * Ask aamarPay directly, by our own transaction id.
     *
     * Their Search Transaction API takes `request_id`, documented as the
     * merchant's own identifier, so this is called with our reference rather
     * than aamarPay's.
     */
    public function verify(string $gatewayReference): GatewayResult
    {
        return $this->search($gatewayReference);
    }

    /**
     * The same call. See the class docblock.
     */
    public function status(string $reference): GatewayResult
    {
        return $this->search($reference);
    }

    protected function search(string $reference): GatewayResult
    {
        $this->requireConfigured();

        $response = $this->http
            ->timeout(20)
            ->get($this->host().'/api/v1/trxcheck/request.php', [
                'request_id' => $reference,
                'store_id' => $this->credentials->storeId(),
                'signature_key' => $this->credentials->signatureKey(),
                'type' => 'json',
            ]);

        if ($response->failed()) {
            throw GatewayUnavailable::forGateway($this->name(), 'HTTP '.$response->status());
        }

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        if ($body === [] || ! isset($body['pay_status'])) {
            return GatewayResult::failed(
                reference: $reference,
                error: 'The gateway has no record of this transaction.',
                errorCode: 'not_found',
                raw: $body,
            );
        }

        $status = mb_strtolower((string) $body['pay_status']);

        $gatewayReference = isset($body['pg_txnid']) && $body['pg_txnid'] !== ''
            ? (string) $body['pg_txnid']
            : $reference;

        if ($status === self::STATUS_SUCCESSFUL) {
            if (! isset($body['amount'], $body['currency'])) {
                throw GatewayUnavailable::malformedResponse($this->name());
            }

            return GatewayResult::paid(
                reference: (string) ($body['mer_txnid'] ?? $reference),
                gatewayReference: $gatewayReference,
                amount: Money::fromDecimal(
                    (string) $body['amount'],
                    Currency::from(mb_strtoupper((string) $body['currency'])),
                ),
                raw: $body,

                // aamarPay's own transaction id. Kept as evidence of which
                // provider transaction this was, since it is not what the
                // lookup is addressed by.
                settlementReference: isset($body['pg_txnid']) && $body['pg_txnid'] !== ''
                    ? (string) $body['pg_txnid']
                    : null,
            );
        }

        /*
         * Not confirmed. Which is not the same as refused: aamarPay publishes
         * only the successful status, so anything else here is a word this
         * driver has no documented meaning for. Pending releases nothing and
         * lets the payment's own deadline close it, which is a better answer
         * than a failure this driver inferred.
         */
        return GatewayResult::pending($reference, $gatewayReference, $body);
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
