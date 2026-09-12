<?php

namespace App\Integrations\Payment\Gateways\Bkash;

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
 * bKash Tokenized Checkout (§26, D7).
 *
 * Built against bKash's published developer documentation: a token granted from
 * an app key and secret, a create call in checkout mode returning a bKash URL,
 * an execute call that completes and confirms the payment, and a refund pair.
 *
 * Three things about this provider are worth knowing before changing anything
 * here.
 *
 * **Execute is the verification.** bKash publishes no endpoint for asking about
 * a payment's status after the fact, so `execute` is both the act of completing
 * the payment and the authoritative answer about it — and the documentation says
 * a payment id is "valid for single execution only". Everything upstream already
 * accounts for this: settlement holds a lock, returns early when the payment is
 * already settled, and never executes twice. What it does mean is that a bKash
 * payment which executes successfully but fails to record cannot be re-asked. It
 * goes to a human, which is why the settlement path writes the provider's answer
 * to the payment log before acting on it. StatusQuery is not declared.
 *
 * **Its notification is signed, but by AWS SNS.** bKash signs each webhook with
 * an SNS message signature validated against a certificate it names in the
 * payload. That is a real scheme, not an absent one — but implementing it means
 * fetching and trusting a certificate URL, and the canonical string it is
 * computed over is not stated on the page that describes it. Written from a
 * guess, it would be a check that looks like protection and is not. So the
 * capability is absent and {@see verifyWebhookSignature()} fails closed until
 * the scheme can be implemented against a document that specifies it.
 *
 * **Its base URL is configuration.** bKash shares it with each merchant during
 * onboarding; the published pattern gives only its shape.
 */
class BkashGateway extends Gateway
{
    /** The mode that means a one-off checkout rather than an agreement. */
    public const MODE_CHECKOUT = '0011';

    /**
     * Authorisation and capture, which is what a checkout payment is.
     *
     * bKash's agreement-based flow uses "Sale" here and its checkout flow uses
     * "authorization". They are different endpoints with different modes, and
     * this driver only does the second.
     */
    public const INTENT_AUTHORIZATION = 'authorization';

    /** bKash's word for a completed payment or refund. */
    public const STATUS_COMPLETED = 'Completed';

    /** The status a payment holds between creation and execution. */
    public const STATUS_INITIATED = 'Initiated';

    public function __construct(
        protected HttpClient $http,
        protected BkashCredentials $credentials,
    ) {}

    /**
     * @return array<int, GatewayCapability>
     */
    public function capabilities(): array
    {
        return [
            GatewayCapability::Initiate,
            GatewayCapability::Verify,

            // "Multiple partial refunds up to 10 times" until the full amount
            // is returned, so both shapes are the same documented call.
            GatewayCapability::RefundFull,
            GatewayCapability::RefundPartial,
            GatewayCapability::RefundStatus,
        ];
    }

    public function initiate(PaymentIntent $intent): GatewayRedirect
    {
        $body = $this->authenticated('post', '/tokenized/checkout/payment/create', [
            'mode' => self::MODE_CHECKOUT,

            // bKash echoes this back on the callback and forbids < > & in it.
            'payerReference' => $intent->reference,

            'callbackURL' => $intent->successUrl,

            // A decimal string, like every other provider.
            'amount' => $intent->amount->toDecimal(),
            'currency' => $intent->amount->currency->value,
            'intent' => self::INTENT_AUTHORIZATION,
            'merchantInvoiceNumber' => $intent->reference,
        ]);

        $url = $body['bkashURL'] ?? null;
        $paymentId = $body['paymentID'] ?? null;

        if (! is_string($url) || $url === '' || ! is_string($paymentId) || $paymentId === '') {
            throw GatewayUnavailable::forGateway(
                $this->name(),
                (string) ($body['statusMessage'] ?? $body['errorMessage'] ?? 'no payment could be created'),
            );
        }

        return new GatewayRedirect(
            url: $url,
            // The payment id is what execute is addressed to, so it has to
            // survive the redirect.
            gatewayReference: $paymentId,
        );
    }

    /**
     * Read the return request.
     *
     * bKash returns with the payment id and its own `status` query parameter.
     * Neither concludes anything — the payment is not complete until execute
     * says so, and until then there is not even any money.
     */
    public function handleCallback(Request $request): GatewayResult
    {
        $paymentId = $request->input('paymentID');
        $paymentId = is_string($paymentId) && $paymentId !== '' ? $paymentId : null;

        if ($paymentId === null) {
            return GatewayResult::failed(
                reference: null,
                error: 'The gateway returned without naming a payment.',
                raw: $request->all(),
            );
        }

        $status = mb_strtolower((string) $request->input('status', ''));

        if ($status === 'cancel') {
            return GatewayResult::cancelled(null, $request->all());
        }

        return GatewayResult::pending(
            reference: '',
            gatewayReference: $paymentId,
            raw: $request->all(),
        );
    }

    /**
     * bKash's webhook signature is not implementable from what is published.
     *
     * See the class docblock. Fails closed rather than pretending.
     */
    public function verifyWebhookSignature(Request $request): bool
    {
        return false;
    }

    /**
     * Complete the payment and take bKash's answer as authoritative.
     *
     * The single call that both finishes a bKash payment and says what happened
     * to it. A payment id executes once, which is why nothing calls this
     * speculatively.
     */
    public function verify(string $gatewayReference): GatewayResult
    {
        $body = $this->authenticated('post', '/tokenized/checkout/execute/', [
            'paymentID' => $gatewayReference,
        ]);

        $status = (string) ($body['transactionStatus'] ?? '');
        $reference = (string) ($body['merchantInvoiceNumber'] ?? '');

        if ($status === self::STATUS_COMPLETED) {
            if (! isset($body['amount'], $body['currency'])) {
                throw GatewayUnavailable::malformedResponse($this->name());
            }

            return GatewayResult::paid(
                reference: $reference,
                gatewayReference: $gatewayReference,
                amount: Money::fromDecimal(
                    (string) $body['amount'],
                    Currency::from(mb_strtoupper((string) $body['currency'])),
                ),
                raw: $body,

                // trxID is what a refund is addressed to, alongside the payment
                // id. Kept now so a refund is one call rather than a lookup
                // that can fail when somebody is already owed their money.
                settlementReference: isset($body['trxID']) && $body['trxID'] !== ''
                    ? (string) $body['trxID']
                    : null,
            );
        }

        /*
         * Still where it started. bKash keeps a created payment at "Initiated"
         * until it is executed, and a payer who has not finished on their phone
         * is not a payer who failed.
         */
        if ($status === self::STATUS_INITIATED || $status === '') {
            return GatewayResult::pending($reference, $gatewayReference, $body);
        }

        return GatewayResult::failed(
            reference: $reference === '' ? null : $reference,
            error: (string) ($body['statusMessage'] ?? $body['errorMessage'] ?? 'The gateway did not confirm this payment.'),
            errorCode: (string) ($body['statusCode'] ?? $body['errorCode'] ?? $status),
            raw: $body,
            gatewayReference: $gatewayReference,
        );
    }

    /**
     * Return money through bKash (§26.3).
     *
     * Addressed to both identifiers: the payment id from creation and the trxID
     * from execution. Only a `Completed` refund has actually returned anything;
     * everything else is still in flight and must not reverse our own records.
     */
    public function refund(RefundIntent $intent): RefundResult
    {
        $this->requireCapability(
            $intent->isFull() ? GatewayCapability::RefundFull : GatewayCapability::RefundPartial,
        );

        $identity = $this->refundIdentity($intent->gatewayReference);

        $body = $this->authenticated('post', '/v2/tokenized-checkout/refund/payment/transaction', [
            'paymentId' => $identity['paymentId'],
            'trxId' => $identity['trxId'],
            'refundAmount' => $intent->amount->toDecimal(),

            // bKash caps this at 255 characters.
            'reason' => mb_substr($intent->reason, 0, 255),
            'sku' => mb_substr($intent->reference, 0, 255),
        ]);

        $status = (string) ($body['refundTransactionStatus'] ?? '');

        $reference = isset($body['refundTrxId']) && $body['refundTrxId'] !== ''
            ? (string) $body['refundTrxId']
            : null;

        if ($status === self::STATUS_COMPLETED) {
            if ($reference === null) {
                throw GatewayUnavailable::malformedResponse($this->name());
            }

            return RefundResult::succeeded(
                gatewayRefundReference: $reference,
                amount: isset($body['refundAmount'], $body['currency'])
                    ? Money::fromDecimal(
                        (string) $body['refundAmount'],
                        Currency::from(mb_strtoupper((string) $body['currency'])),
                    )
                    : null,
                raw: $body,
            );
        }

        /*
         * Accepted with a reference but not completed is in flight. Without a
         * reference there is nothing to ask about later, so it is a refusal
         * rather than something to record and lose track of.
         */
        if ($reference !== null && ! isset($body['errorCode'])) {
            return RefundResult::pending(
                gatewayRefundReference: $reference,
                amount: $intent->amount,
                raw: $body,
            );
        }

        return RefundResult::failed(
            error: (string) ($body['statusMessage'] ?? $body['errorMessage'] ?? 'The gateway refused this refund.'),
            errorCode: (string) ($body['statusCode'] ?? $body['errorCode'] ?? ($status !== '' ? $status : 'unknown')),
            raw: $body,
            gatewayRefundReference: $reference,
        );
    }

    /**
     * What became of a refund already submitted.
     *
     * bKash's status call is addressed by the original payment, not by the
     * refund, and answers with every refund made against it — so the one being
     * asked about is picked out of that list by its own id.
     */
    public function refundStatus(string $gatewayRefundReference): RefundResult
    {
        $identity = $this->refundIdentity($gatewayRefundReference, expectRefund: true);

        $body = $this->authenticated('post', '/v2/tokenized-checkout/refund/payment/status', [
            'paymentId' => $identity['paymentId'],
            'trxId' => $identity['trxId'],
        ]);

        $transactions = $body['refundTransactions'] ?? null;

        if (! is_array($transactions)) {
            throw GatewayUnavailable::malformedResponse($this->name());
        }

        foreach ($transactions as $transaction) {
            if (! is_array($transaction)) {
                continue;
            }

            if ((string) ($transaction['refundTrxId'] ?? '') !== $identity['refundTrxId']) {
                continue;
            }

            $status = (string) ($transaction['refundTransactionStatus'] ?? '');

            if ($status === self::STATUS_COMPLETED) {
                return RefundResult::succeeded(
                    gatewayRefundReference: $identity['refundTrxId'],
                    amount: isset($transaction['refundAmount'])
                        ? Money::fromDecimal((string) $transaction['refundAmount'], Currency::BDT)
                        : null,
                    raw: $transaction,
                );
            }

            return RefundResult::pending(
                gatewayRefundReference: $identity['refundTrxId'],
                raw: $transaction,
            );
        }

        /*
         * bKash knows the payment but not this refund. Not a completed refund
         * and not a failed one either — an answer that does not mention it is
         * not an answer about it, and reading silence as failure would be the
         * worst possible guess about money owed to somebody.
         */
        return RefundResult::pending(
            gatewayRefundReference: $identity['refundTrxId'],
            raw: $body,
        );
    }

    /**
     * Pull bKash's two (or three) identifiers out of one reference.
     *
     * bKash addresses refunds by a pair — the payment id and the transaction id
     * — and its refund status call by that pair plus the refund's own id. The
     * contract carries one string, so they travel joined by a colon and are
     * split here rather than the rest of the application learning bKash's
     * addressing.
     *
     * @return array{paymentId: string, trxId: string, refundTrxId: string}
     */
    protected function refundIdentity(string $reference, bool $expectRefund = false): array
    {
        $parts = explode(':', $reference);

        $expected = $expectRefund ? 3 : 2;

        if (count($parts) < $expected) {
            throw GatewayUnavailable::forGateway(
                $this->name(),
                'a bKash refund needs the payment id and transaction id together',
            );
        }

        return [
            'paymentId' => $parts[0],
            'trxId' => $parts[1],
            'refundTrxId' => $parts[2] ?? '',
        ];
    }

    /**
     * One authenticated call, with bKash's connection-level answer checked.
     *
     * @param  array<string, string>  $payload
     * @return array<string, mixed>
     */
    protected function authenticated(string $method, string $path, array $payload): array
    {
        $token = $this->token();

        $response = $this->http
            ->asJson()
            ->timeout(30)
            ->withHeaders([
                'Accept' => 'application/json',
                'Authorization' => $token,
                'X-App-Key' => $this->credentials->appKey(),
            ])
            ->{$method}($this->credentials->baseUrl().$path, $payload);

        if ($response->failed()) {
            throw GatewayUnavailable::forGateway($this->name(), 'HTTP '.$response->status());
        }

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        return $body;
    }

    /**
     * Authenticate.
     *
     * The username and password go in headers and the app key and secret in the
     * body — an arrangement worth not tidying, because it is bKash's.
     */
    protected function token(): string
    {
        $this->requireConfigured();

        $response = $this->http
            ->asJson()
            ->timeout(30)
            ->withHeaders([
                'Accept' => 'application/json',
                'username' => $this->credentials->username(),
                'password' => $this->credentials->password(),
            ])
            ->post($this->credentials->baseUrl().'/tokenized/checkout/token/grant', [
                'app_key' => $this->credentials->appKey(),
                'app_secret' => $this->credentials->appSecret(),
            ]);

        if ($response->failed()) {
            throw GatewayUnavailable::forGateway($this->name(), 'HTTP '.$response->status());
        }

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        $token = $body['id_token'] ?? null;

        if (! is_string($token) || $token === '') {
            throw GatewayUnavailable::forGateway($this->name(), 'authentication was refused');
        }

        return $token;
    }

    protected function credentials(): GatewayCredentials
    {
        return $this->credentials;
    }
}
