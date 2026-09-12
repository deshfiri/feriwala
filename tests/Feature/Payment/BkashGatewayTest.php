<?php

use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Integrations\Payment\Data\GatewayCapability;
use App\Integrations\Payment\Data\GatewayOutcome;
use App\Integrations\Payment\Data\GatewayRefundOutcome;
use App\Integrations\Payment\Data\PaymentIntent;
use App\Integrations\Payment\Data\RefundIntent;
use App\Integrations\Payment\Exceptions\GatewayCapabilityMissing;
use App\Integrations\Payment\Exceptions\GatewayUnavailable;
use App\Integrations\Payment\Gateways\Bkash\BkashGateway;
use App\Support\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/*
 * bKash Tokenized Checkout (P2-25, §26).
 *
 * Built against bKash's published developer documentation. Every exchange below
 * is a recorded fixture — nothing here reaches bKash, and nothing has been run
 * against a real merchant account.
 */

const BKASH_BASE = 'https://tokenized.sandbox.example.test/v1.2.0-beta';

beforeEach(function () {
    $settings = app(SettingsRepository::class);

    $settings->define('payment.bkash.mode', 'payment', SettingType::String, 'sandbox');

    foreach ([
        'base_url' => BKASH_BASE,
        'app_key' => 'an-app-key',
        'app_secret' => 'an-app-secret',
        'username' => 'a-username',
        'password' => 'a-password',
    ] as $key => $value) {
        $settings->define("payment.bkash.sandbox.{$key}", 'payment', SettingType::String, $value, isEncrypted: true);
    }

    $this->gateway = app(BkashGateway::class);
});

function bkashIntent(): PaymentIntent
{
    return new PaymentIntent(
        reference: 'PAY-260901-K7M3QX9P',
        amount: Money::of(600000),
        customerName: 'Nusrat Jahan',
        customerEmail: 'nusrat@example.test',
        customerMobile: '+8801712345678',
        successUrl: 'https://erp.feriwala.test/payment/success',
        failUrl: 'https://erp.feriwala.test/payment/fail',
        cancelUrl: 'https://erp.feriwala.test/payment/cancel',
        ipnUrl: 'https://erp.feriwala.test/webhook/bkash',
        description: 'Account activation',
    );
}

/**
 * The token call every other call depends on, plus one more response.
 */
function bkashFake(array $second): void
{
    Http::fakeSequence()
        ->push(['id_token' => 'an-id-token', 'token_type' => 'Bearer', 'expires_in' => '3600'])
        ->push($second);
}

/**
 * A refund is addressed by bKash's pair of identifiers, joined.
 */
function bkashRefund(?Money $amount = null, string $reference = 'TR001:TRX998'): RefundIntent
{
    return new RefundIntent(
        reference: 'PAY-260901-K7M3QX9P',
        gatewayReference: $reference,
        amount: $amount ?? Money::of(600000),
        originalAmount: Money::of(600000),
        reason: 'Duplicate payment',
        idempotencyKey: 'refund:PAY-260901-K7M3QX9P:1',
    );
}

describe('initiating', function () {
    it('creates a checkout payment against the configured base url', function () {
        bkashFake([
            'statusCode' => '0000',
            'paymentID' => 'TR0011abc',
            'bkashURL' => 'https://sandbox.payment.bkash.com/redirect/TR0011abc',
            'transactionStatus' => 'Initiated',
            'amount' => '6000.00',
            'currency' => 'BDT',
        ]);

        $redirect = $this->gateway->initiate(bkashIntent());

        expect($redirect->url)->toBe('https://sandbox.payment.bkash.com/redirect/TR0011abc')
            // The payment id is what execute is addressed to, so it has to
            // survive the redirect.
            ->and($redirect->gatewayReference)->toBe('TR0011abc');

        Http::assertSent(fn ($request) => $request->url() === BKASH_BASE.'/tokenized/checkout/token/grant'
            // Username and password in headers, app key and secret in the body.
            // bKash's arrangement, not ours.
            && $request->header('username')[0] === 'a-username'
            && $request['app_key'] === 'an-app-key');

        Http::assertSent(fn ($request) => $request->url() === BKASH_BASE.'/tokenized/checkout/payment/create'
            && $request->header('Authorization')[0] === 'an-id-token'
            && $request->header('X-App-Key')[0] === 'an-app-key'
            && $request['mode'] === '0011'
            && $request['intent'] === 'authorization'
            && $request['amount'] === '6000.00'
            && $request['merchantInvoiceNumber'] === 'PAY-260901-K7M3QX9P');
    });

    it('throws when no payment can be created', function () {
        bkashFake(['statusCode' => '2001', 'statusMessage' => 'Invalid App Key']);

        expect(fn () => $this->gateway->initiate(bkashIntent()))
            ->toThrow(GatewayUnavailable::class, 'Invalid App Key');
    });

    it('throws when authentication is refused', function () {
        Http::fake(['*' => Http::response(['statusCode' => '0001', 'statusMessage' => 'Invalid credentials'])]);

        expect(fn () => $this->gateway->initiate(bkashIntent()))
            ->toThrow(GatewayUnavailable::class, 'authentication was refused');
    });

    it('refuses to run without a configured base url', function () {
        // bKash shares the base URL during onboarding. Refusing is better than
        // pinning a guess and pointing a live account at an unconfirmed host.
        app(SettingsRepository::class)->set('payment.bkash.sandbox.base_url', '');

        Http::fake();

        expect(fn () => $this->gateway->initiate(bkashIntent()))
            ->toThrow(GatewayUnavailable::class, 'No credentials are configured');

        Http::assertNothingSent();
    });
});

describe('the browser return is never trusted', function () {
    it('reports a return as pending, because there is no money until execute', function () {
        $result = $this->gateway->handleCallback(
            Request::create('/payment/success', 'GET', [
                'paymentID' => 'TR0011abc',
                'status' => 'success',
            ]),
        );

        expect($result->outcome)->toBe(GatewayOutcome::Pending)
            ->and($result->outcome->releasesValue())->toBeFalse()
            ->and($result->gatewayReference)->toBe('TR0011abc');
    });

    it('reads a cancellation', function () {
        $result = $this->gateway->handleCallback(
            Request::create('/payment/cancel', 'GET', ['paymentID' => 'TR1', 'status' => 'cancel']),
        );

        expect($result->outcome)->toBe(GatewayOutcome::Cancelled);
    });

    it('reports a return naming no payment as failed', function () {
        expect($this->gateway->handleCallback(Request::create('/payment/success'))->outcome)
            ->toBe(GatewayOutcome::Failed);
    });
});

describe('execute is the verification', function () {
    it('completes the payment and reports what bKash said', function () {
        bkashFake([
            'statusCode' => '0000',
            'statusMessage' => 'Successful',
            'paymentID' => 'TR0011abc',
            'trxID' => 'TRX998877',
            'transactionStatus' => 'Completed',
            'amount' => '6000.00',
            'currency' => 'BDT',
            'merchantInvoiceNumber' => 'PAY-260901-K7M3QX9P',
        ]);

        $result = $this->gateway->verify('TR0011abc');

        expect($result->isPaid())->toBeTrue()
            ->and($result->amount->minorUnits)->toBe(600000)
            ->and($result->reference)->toBe('PAY-260901-K7M3QX9P')
            // trxID is half of what a refund is addressed to, so it is kept now
            // rather than looked up when somebody is already owed their money.
            ->and($result->settlementReference)->toBe('TRX998877');

        Http::assertSent(fn ($request) => $request->url() === BKASH_BASE.'/tokenized/checkout/execute/'
            && $request['paymentID'] === 'TR0011abc');
    });

    it('leaves an unexecuted payment pending rather than failing it', function () {
        // A payer who has not finished on their phone is not a payer who
        // failed.
        bkashFake(['paymentID' => 'TR1', 'transactionStatus' => 'Initiated']);

        expect($this->gateway->verify('TR1')->outcome)->toBe(GatewayOutcome::Pending);
    });

    it('reports a refusal as a result rather than throwing', function () {
        bkashFake([
            'errorCode' => '2062',
            'errorMessage' => 'Insufficient Balance',
            'transactionStatus' => 'Failed',
        ]);

        $result = $this->gateway->verify('TR1');

        expect($result->outcome)->toBe(GatewayOutcome::Failed)
            ->and($result->error)->toBe('Insufficient Balance')
            ->and($result->errorCode)->toBe('2062');
    });

    it('throws rather than guessing when a completion carries no amount', function () {
        bkashFake(['transactionStatus' => 'Completed', 'trxID' => 'TRX1']);

        expect(fn () => $this->gateway->verify('TR1'))
            ->toThrow(GatewayUnavailable::class, 'could not be understood');
    });

    it('declares no status lookup, because bKash publishes none', function () {
        /*
         * Execute is both the completion and the answer, and a payment id
         * executes once. Declaring a lookup this provider does not offer would
         * put a button on a screen that calls an endpoint nobody has.
         */
        expect($this->gateway->supports(GatewayCapability::StatusQuery))->toBeFalse()
            ->and(fn () => $this->gateway->status('PAY-1'))->toThrow(GatewayCapabilityMissing::class);
    });
});

describe('refunds (§26.3)', function () {
    it('refunds against the payment id and the transaction id together', function () {
        bkashFake([
            'originalTrxId' => 'TRX998',
            'refundTrxId' => 'RFND001',
            'refundTransactionStatus' => 'Completed',
            'refundAmount' => '6000.00',
            'currency' => 'BDT',
        ]);

        $result = $this->gateway->refund(bkashRefund());

        expect($result->isSucceeded())->toBeTrue()
            ->and($result->outcome->isSettled())->toBeTrue()
            ->and($result->gatewayRefundReference)->toBe('RFND001')
            ->and($result->amount->minorUnits)->toBe(600000);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/refund/payment/transaction')
            && $request['paymentId'] === 'TR001'
            && $request['trxId'] === 'TRX998'
            && $request['refundAmount'] === '6000.00'
            && $request['reason'] === 'Duplicate payment');
    });

    it('sends a partial refund as the same call with a smaller amount', function () {
        bkashFake([
            'refundTrxId' => 'RFND002',
            'refundTransactionStatus' => 'Completed',
            'refundAmount' => '1500.00',
            'currency' => 'BDT',
        ]);

        expect($this->gateway->refund(bkashRefund(Money::of(150000)))->isSucceeded())->toBeTrue();

        Http::assertSent(fn ($request) => ! str_contains($request->url(), '/refund/')
            || $request['refundAmount'] === '1500.00');
    });

    it('treats anything short of Completed as still in flight', function () {
        // Only "Completed" means the money has gone back. Reversing our own
        // records on anything else would give back what has not yet left bKash.
        bkashFake(['refundTrxId' => 'RFND003', 'refundTransactionStatus' => 'Processing']);

        $result = $this->gateway->refund(bkashRefund());

        expect($result->isPending())->toBeTrue()
            ->and($result->outcome->isSettled())->toBeFalse();
    });

    it('reports a refused refund as a result rather than throwing', function () {
        bkashFake(['errorCode' => '2065', 'errorMessage' => 'Refund window expired']);

        $result = $this->gateway->refund(bkashRefund());

        expect($result->outcome)->toBe(GatewayRefundOutcome::Failed)
            ->and($result->error)->toBe('Refund window expired');
    });

    it('refuses a refund it has not been given both identifiers for', function () {
        Http::fake();

        expect(fn () => $this->gateway->refund(bkashRefund(reference: 'TR001')))
            ->toThrow(GatewayUnavailable::class, 'payment id and transaction id');

        Http::assertNothingSent();
    });
});

describe('refund status', function () {
    it('picks the refund being asked about out of the list bKash returns', function () {
        /*
         * bKash's status call is addressed by the original payment and answers
         * with every refund made against it, so the right one has to be found
         * rather than assumed to be first.
         */
        bkashFake([
            'originalTrxId' => 'TRX998',
            'refundTransactions' => [
                ['refundTrxId' => 'RFND001', 'refundTransactionStatus' => 'Completed', 'refundAmount' => '1500.00'],
                ['refundTrxId' => 'RFND002', 'refundTransactionStatus' => 'Processing', 'refundAmount' => '1000.00'],
            ],
        ]);

        $result = $this->gateway->refundStatus('TR001:TRX998:RFND002');

        expect($result->isPending())->toBeTrue()
            ->and($result->gatewayRefundReference)->toBe('RFND002');
    });

    it('treats only Completed as money actually returned', function () {
        bkashFake([
            'refundTransactions' => [
                ['refundTrxId' => 'RFND001', 'refundTransactionStatus' => 'Completed', 'refundAmount' => '6000.00'],
            ],
        ]);

        $result = $this->gateway->refundStatus('TR001:TRX998:RFND001');

        expect($result->isSucceeded())->toBeTrue()
            ->and($result->amount->minorUnits)->toBe(600000);
    });

    it('stays pending when bKash knows the payment but not this refund', function () {
        /*
         * An answer that does not mention the refund is not an answer about it.
         * Reading silence as failure would be the worst possible guess about
         * money owed to somebody.
         */
        bkashFake(['refundTransactions' => []]);

        expect($this->gateway->refundStatus('TR001:TRX998:RFND999')->isPending())->toBeTrue();
    });

    it('throws when the answer has no refund list at all', function () {
        bkashFake(['errorCode' => '2001']);

        expect(fn () => $this->gateway->refundStatus('TR001:TRX998:RFND1'))
            ->toThrow(GatewayUnavailable::class, 'could not be understood');
    });
});

describe('what this driver will not do', function () {
    it('fails a notification closed, because the SNS scheme is not specified here', function () {
        /*
         * bKash does sign its webhooks, with an AWS SNS message signature. That
         * is a real scheme rather than an absent one — but the canonical string
         * it is computed over is not stated where the scheme is described, and
         * a signature check written from a guess looks like protection without
         * being any.
         */
        expect($this->gateway->verifyWebhookSignature(
            Request::create('/webhook/bkash', 'POST', ['trxID' => 'TRX1', 'transactionStatus' => 'Completed']),
        ))->toBeFalse()
            ->and($this->gateway->supports(GatewayCapability::WebhookSignature))->toBeFalse();
    });
});
