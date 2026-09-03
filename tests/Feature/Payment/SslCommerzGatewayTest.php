<?php

use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Integrations\Payment\Data\GatewayOutcome;
use App\Integrations\Payment\Data\PaymentIntent;
use App\Integrations\Payment\Exceptions\GatewayUnavailable;
use App\Integrations\Payment\Gateways\SslCommerz\SslCommerzGateway;
use App\Support\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

const STORE_ID = 'feriwala_test';
const STORE_PASSWORD = 'test-store-password';

beforeEach(function () {
    $settings = app(SettingsRepository::class);

    $settings->define('payment.sslcommerz.mode', 'payment', SettingType::String, 'sandbox');
    $settings->define('payment.sslcommerz.sandbox.store_id', 'payment', SettingType::String, STORE_ID, isEncrypted: true);
    $settings->define('payment.sslcommerz.sandbox.store_password', 'payment', SettingType::String, STORE_PASSWORD, isEncrypted: true);

    $this->gateway = app(SslCommerzGateway::class);
});

function anIntent(): PaymentIntent
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
        ipnUrl: 'https://erp.feriwala.test/webhook/sslcommerz',
        description: 'Account activation',
    );
}

/**
 * Build a valid IPN exactly as SSLCommerz does, so the verifier is tested
 * against the real algorithm rather than against itself.
 *
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function signedIpn(array $overrides = []): array
{
    $fields = [
        'tran_id' => 'PAY-260901-K7M3QX9P',
        'val_id' => 'VAL123456',
        'amount' => '6000.00',
        'currency' => 'BDT',
        'status' => 'VALID',
        'store_id' => STORE_ID,
        ...$overrides,
    ];

    $verifyKey = implode(',', array_keys($fields));

    // SSLCommerz hashes exactly the fields verify_key names, plus the md5 of the
    // store password — verify_key and verify_sign are not themselves part of it.
    $pairs = $fields;
    $pairs['store_passwd'] = md5(STORE_PASSWORD);

    ksort($pairs);

    $built = [];
    foreach ($pairs as $key => $value) {
        $built[] = $key.'='.$value;
    }

    return [
        ...$fields,
        'verify_key' => $verifyKey,
        'verify_sign' => md5(implode('&', $built)),
    ];
}

function ipnRequest(array $payload): Request
{
    return Request::create('/webhook/sslcommerz', 'POST', $payload);
}

describe('credentials', function () {
    it('uses the sandbox host in sandbox mode', function () {
        expect($this->gateway->isSandbox())->toBeTrue();
    });

    it('refuses to run without configured credentials', function () {
        app(SettingsRepository::class)->set('payment.sslcommerz.sandbox.store_id', '');

        Http::fake();

        expect(fn () => $this->gateway->initiate(anIntent()))
            ->toThrow(GatewayUnavailable::class, 'No credentials are configured');
    });
});

describe('initiating', function () {
    it('sends the amount as a decimal string, not minor units', function () {
        Http::fake([
            '*' => Http::response(['status' => 'SUCCESS', 'GatewayPageURL' => 'https://pay.test/go']),
        ]);

        $this->gateway->initiate(anIntent());

        Http::assertSent(function ($request) {
            // 600000 poisha must reach the gateway as "6000.00", not "600000".
            return $request['total_amount'] === '6000.00'
                && $request['currency'] === 'BDT'
                && $request['tran_id'] === 'PAY-260901-K7M3QX9P';
        });
    });

    it('returns the gateway page url', function () {
        Http::fake([
            '*' => Http::response([
                'status' => 'SUCCESS',
                'GatewayPageURL' => 'https://pay.test/go',
                'sessionkey' => 'SESS123',
            ]),
        ]);

        $redirect = $this->gateway->initiate(anIntent());

        expect($redirect->url)->toBe('https://pay.test/go')
            ->and($redirect->gatewayReference)->toBe('SESS123');
    });

    it('throws when the gateway refuses the session', function () {
        Http::fake([
            '*' => Http::response(['status' => 'FAILED', 'failedreason' => 'Store not active']),
        ]);

        expect(fn () => $this->gateway->initiate(anIntent()))
            ->toThrow(GatewayUnavailable::class, 'Store not active');
    });

    it('throws when the gateway is unreachable', function () {
        Http::fake(['*' => Http::response('', 503)]);

        expect(fn () => $this->gateway->initiate(anIntent()))
            ->toThrow(GatewayUnavailable::class);
    });
});

describe('IPN signature verification (§26.4)', function () {
    it('accepts a genuine signature', function () {
        expect($this->gateway->verifyWebhookSignature(ipnRequest(signedIpn())))->toBeTrue();
    });

    it('rejects a tampered amount', function () {
        // The attack this exists to stop: change the amount, keep the signature.
        $ipn = signedIpn();
        $ipn['amount'] = '999999.00';

        expect($this->gateway->verifyWebhookSignature(ipnRequest($ipn)))->toBeFalse();
    });

    it('rejects a tampered status', function () {
        $ipn = signedIpn(['status' => 'FAILED']);
        $ipn['status'] = 'VALID';

        expect($this->gateway->verifyWebhookSignature(ipnRequest($ipn)))->toBeFalse();
    });

    it('rejects a forged signature', function () {
        $ipn = signedIpn();
        $ipn['verify_sign'] = md5('guessed');

        expect($this->gateway->verifyWebhookSignature(ipnRequest($ipn)))->toBeFalse();
    });

    it('rejects a request with no signature at all', function () {
        $ipn = signedIpn();
        unset($ipn['verify_sign']);

        expect($this->gateway->verifyWebhookSignature(ipnRequest($ipn)))->toBeFalse();
    });

    it('rejects a request with no verify_key', function () {
        $ipn = signedIpn();
        unset($ipn['verify_key']);

        expect($this->gateway->verifyWebhookSignature(ipnRequest($ipn)))->toBeFalse();
    });

    it('rejects an empty request', function () {
        expect($this->gateway->verifyWebhookSignature(ipnRequest([])))->toBeFalse();
    });

    it('ignores extra fields an attacker appends', function () {
        // Only the fields verify_key names are signed, so adding others must
        // neither break a genuine signature nor let one be forged.
        $ipn = signedIpn();
        $ipn['injected_field'] = 'malicious';

        expect($this->gateway->verifyWebhookSignature(ipnRequest($ipn)))->toBeTrue();
    });

    it('rejects a signature built with the wrong store password', function () {
        app(SettingsRepository::class)->set('payment.sslcommerz.sandbox.store_password', 'different');

        expect(app(SslCommerzGateway::class)->verifyWebhookSignature(ipnRequest(signedIpn())))
            ->toBeFalse();
    });
});

describe('the browser callback is never trusted', function () {
    it('reports an apparent success as pending, not paid', function () {
        // A user can edit anything in that redirect. Only the validation API
        // is authoritative.
        $result = $this->gateway->handleCallback(
            Request::create('/payment/success', 'POST', [
                'tran_id' => 'PAY-260901-K7M3QX9P',
                'val_id' => 'VAL123456',
                'status' => 'VALID',
            ]),
        );

        expect($result->outcome)->toBe(GatewayOutcome::Pending)
            ->and($result->isPaid())->toBeFalse()
            ->and($result->outcome->releasesValue())->toBeFalse();
    });

    it('reads a cancellation', function () {
        $result = $this->gateway->handleCallback(
            Request::create('/payment/cancel', 'POST', [
                'tran_id' => 'PAY-1',
                'status' => 'CANCELLED',
            ]),
        );

        expect($result->outcome)->toBe(GatewayOutcome::Cancelled);
    });

    it('reads a failure', function () {
        $result = $this->gateway->handleCallback(
            Request::create('/payment/fail', 'POST', [
                'tran_id' => 'PAY-1',
                'status' => 'FAILED',
                'error' => 'Insufficient funds',
            ]),
        );

        expect($result->outcome)->toBe(GatewayOutcome::Failed)
            ->and($result->error)->toBe('Insufficient funds');
    });
});

describe('server-side verification', function () {
    it('reports a settled payment with the gateway amount', function () {
        Http::fake([
            '*' => Http::response([
                'status' => 'VALID',
                'tran_id' => 'PAY-260901-K7M3QX9P',
                'currency_amount' => '6000.00',
                'currency_type' => 'BDT',
            ]),
        ]);

        $result = $this->gateway->verify('VAL123456');

        expect($result->isPaid())->toBeTrue()
            ->and($result->amount->minorUnits)->toBe(600000)
            ->and($result->matchesAmount(Money::of(600000)))->toBeTrue();
    });

    it('detects an amount that does not match what was expected', function () {
        // A mismatch means tampering or misconfiguration — either way it is not
        // payment for this order.
        Http::fake([
            '*' => Http::response([
                'status' => 'VALID',
                'tran_id' => 'PAY-1',
                'currency_amount' => '10.00',
                'currency_type' => 'BDT',
            ]),
        ]);

        $result = $this->gateway->verify('VAL123456');

        expect($result->isPaid())->toBeTrue()
            ->and($result->matchesAmount(Money::of(600000)))->toBeFalse();
    });

    it('reports a pending payment as pending', function () {
        Http::fake(['*' => Http::response(['status' => 'PENDING', 'tran_id' => 'PAY-1'])]);

        expect($this->gateway->verify('VAL1')->outcome)->toBe(GatewayOutcome::Pending);
    });

    it('reports a failed payment as failed', function () {
        Http::fake(['*' => Http::response(['status' => 'INVALID_TRANSACTION', 'tran_id' => 'PAY-1'])]);

        $result = $this->gateway->verify('VAL1');

        expect($result->outcome)->toBe(GatewayOutcome::Failed)
            ->and($result->errorCode)->toBe('INVALID_TRANSACTION');
    });

    it('throws rather than guessing when the response is malformed', function () {
        // "Valid" with no amount tells us nothing safe to act on.
        Http::fake(['*' => Http::response(['status' => 'VALID', 'tran_id' => 'PAY-1'])]);

        expect(fn () => $this->gateway->verify('VAL1'))
            ->toThrow(GatewayUnavailable::class, 'could not be understood');
    });

    it('throws when the validator is unreachable', function () {
        Http::fake(['*' => Http::response('', 500)]);

        expect(fn () => $this->gateway->verify('VAL1'))->toThrow(GatewayUnavailable::class);
    });
});
