<?php

use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Integrations\Payment\Data\GatewayCapability;
use App\Integrations\Payment\Data\GatewayOutcome;
use App\Integrations\Payment\Data\PaymentIntent;
use App\Integrations\Payment\Exceptions\GatewayCapabilityMissing;
use App\Integrations\Payment\Exceptions\GatewayUnavailable;
use App\Integrations\Payment\Gateways\AmarPay\AmarPayGateway;
use App\Support\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/*
 * aamarPay (P2-24, §26).
 *
 * Built against aamarPay's published API reference. Every exchange below is a
 * recorded fixture — nothing here reaches aamarPay, and nothing has been run
 * against a real merchant account.
 */

beforeEach(function () {
    $settings = app(SettingsRepository::class);

    $settings->define('payment.amarpay.mode', 'payment', SettingType::String, 'sandbox');
    $settings->define('payment.amarpay.sandbox.store_id', 'payment', SettingType::String, 'aamarpaytest', isEncrypted: true);
    $settings->define('payment.amarpay.sandbox.signature_key', 'payment', SettingType::String, 'a-signature-key', isEncrypted: true);

    $this->gateway = app(AmarPayGateway::class);
});

function amarPayIntent(): PaymentIntent
{
    return new PaymentIntent(
        reference: 'PAY-260901-K7M3QX9P',
        amount: Money::fromDecimal('6000.00'),
        customerName: 'Nusrat Jahan',
        customerEmail: 'nusrat@example.test',
        customerMobile: '+8801712345678',
        successUrl: 'https://erp.feriwala.test/payment/success',
        failUrl: 'https://erp.feriwala.test/payment/fail',
        cancelUrl: 'https://erp.feriwala.test/payment/cancel',
        ipnUrl: 'https://erp.feriwala.test/webhook/amarpay',
        description: 'Account activation',
    );
}

describe('initiating', function () {
    it('sends the amount as a decimal string with the store credentials', function () {
        Http::fake(['*' => Http::response(['result' => 'true', 'payment_url' => 'paymentprocess.php?track=abc'])]);

        $this->gateway->initiate(amarPayIntent());

        Http::assertSent(fn ($request) => $request['amount'] === '6000.00'
            && $request['currency'] === 'BDT'
            && $request['tran_id'] === 'PAY-260901-K7M3QX9P'
            && $request['type'] === 'json');
    });

    it('resolves a relative payment url against the host it is pointed at', function () {
        /*
         * aamarPay returns a path. Resolving it against this driver's own host
         * is what stops a sandbox initiation producing a live checkout link.
         */
        Http::fake(['*' => Http::response(['payment_url' => 'paymentprocess.php?track=abc'])]);

        expect($this->gateway->initiate(amarPayIntent())->url)
            ->toBe(AmarPayGateway::SANDBOX_HOST.'/paymentprocess.php?track=abc');
    });

    it('leaves an absolute payment url alone', function () {
        Http::fake(['*' => Http::response(['payment_url' => 'https://sandbox.aamarpay.com/go?t=1'])]);

        expect($this->gateway->initiate(amarPayIntent())->url)
            ->toBe('https://sandbox.aamarpay.com/go?t=1');
    });

    it('throws when no payment url comes back', function () {
        Http::fake(['*' => Http::response(['result' => 'Invalid Store ID'])]);

        expect(fn () => $this->gateway->initiate(amarPayIntent()))
            ->toThrow(GatewayUnavailable::class, 'Invalid Store ID');
    });

    it('refuses to run without configured credentials', function () {
        app(SettingsRepository::class)->set('payment.amarpay.sandbox.signature_key', '');

        Http::fake();

        expect(fn () => $this->gateway->initiate(amarPayIntent()))
            ->toThrow(GatewayUnavailable::class, 'No credentials are configured');

        Http::assertNothingSent();
    });
});

describe('the browser return is never trusted', function () {
    it('reports a return as pending even when it claims to have failed', function () {
        /*
         * aamarPay is looked up by our own reference, so nothing is lost by
         * asking — whereas believing a redirect that says "failed" would
         * abandon a payment anybody could have marked failed by editing a URL.
         */
        $result = $this->gateway->handleCallback(
            Request::create('/payment/fail', 'POST', [
                'mer_txnid' => 'PAY-260901-K7M3QX9P',
                'pay_status' => 'Failed',
            ]),
        );

        expect($result->outcome)->toBe(GatewayOutcome::Pending)
            ->and($result->outcome->releasesValue())->toBeFalse();
    });

    it('reports a return naming no transaction as failed', function () {
        expect($this->gateway->handleCallback(Request::create('/payment/success'))->outcome)
            ->toBe(GatewayOutcome::Failed);
    });
});

describe('verification is the authoritative answer', function () {
    it('asks by our own transaction id, which is what aamarPay looks up by', function () {
        Http::fake(['*' => Http::response([
            'pay_status' => 'Successful',
            'status_code' => '2',
            'mer_txnid' => 'PAY-260901-K7M3QX9P',
            'pg_txnid' => 'AP998877',
            'amount' => '6000.00',
            'currency' => 'BDT',
        ])]);

        $result = $this->gateway->verify('PAY-260901-K7M3QX9P');

        expect($result->isPaid())->toBeTrue()
            ->and($result->amount->toDecimal())->toBe('6000.00')
            // aamarPay's own transaction id, kept as evidence of which provider
            // transaction this was — it is not what the lookup is addressed by.
            ->and($result->settlementReference)->toBe('AP998877');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/v1/trxcheck/request.php')
            && $request['request_id'] === 'PAY-260901-K7M3QX9P');
    });

    it('answers the status lookup with the same call', function () {
        Http::fake(['*' => Http::response([
            'pay_status' => 'Successful',
            'mer_txnid' => 'PAY-1',
            'pg_txnid' => 'AP1',
            'amount' => '100.00',
            'currency' => 'BDT',
        ])]);

        expect($this->gateway->status('PAY-1')->isPaid())->toBeTrue()
            ->and($this->gateway->supports(GatewayCapability::StatusQuery))->toBeTrue();
    });

    it('leaves an undocumented status pending rather than inferring a failure', function () {
        /*
         * aamarPay publishes only the successful status. A guessed spelling for
         * failure either never matches or matches the wrong thing, so anything
         * unrecognised releases nothing and the payment's own deadline closes
         * it.
         */
        Http::fake(['*' => Http::response(['pay_status' => 'Failed', 'mer_txnid' => 'PAY-1'])]);

        expect($this->gateway->verify('PAY-1')->outcome)->toBe(GatewayOutcome::Pending);
    });

    it('reports a transaction the gateway has no record of', function () {
        Http::fake(['*' => Http::response([])]);

        $result = $this->gateway->verify('PAY-NOTHING');

        expect($result->outcome)->toBe(GatewayOutcome::Failed)
            ->and($result->errorCode)->toBe('not_found');
    });

    it('throws rather than guessing when a success carries no amount', function () {
        Http::fake(['*' => Http::response(['pay_status' => 'Successful', 'mer_txnid' => 'PAY-1'])]);

        expect(fn () => $this->gateway->verify('PAY-1'))
            ->toThrow(GatewayUnavailable::class, 'could not be understood');
    });
});

describe('what this driver will not do', function () {
    it('fails a notification closed, because the signature scheme is not published', function () {
        /*
         * aamarPay says its notifications are signed but does not state the
         * algorithm, the signed fields, or where the signature arrives. A
         * signature check written from a guess is worse than none, because it
         * looks like protection.
         */
        expect($this->gateway->verifyWebhookSignature(
            Request::create('/webhook/amarpay', 'POST', ['mer_txnid' => 'PAY-1', 'pay_status' => 'Successful']),
        ))->toBeFalse()
            ->and($this->gateway->supports(GatewayCapability::WebhookSignature))->toBeFalse();
    });

    it('offers no refund, because aamarPay documents no refund API', function () {
        expect($this->gateway->supports(GatewayCapability::RefundFull))->toBeFalse()
            ->and($this->gateway->supports(GatewayCapability::RefundPartial))->toBeFalse()
            ->and(fn () => $this->gateway->refundStatus('anything'))
            ->toThrow(GatewayCapabilityMissing::class);
    });
});
