<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Billing\Actions\ConfigureGateway;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Integrations\Payment\Data\GatewayCapability;
use App\Integrations\Payment\Data\GatewayOutcome;
use App\Integrations\Payment\Data\GatewayRefundOutcome;
use App\Integrations\Payment\Data\PaymentIntent;
use App\Integrations\Payment\Data\RefundIntent;
use App\Integrations\Payment\Exceptions\GatewayUnavailable;
use App\Integrations\Payment\Gateways\PayPal\PayPalGateway;
use App\Integrations\Payment\PaymentGatewayManager;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/*
 * PayPal Orders v2 (P2-28, §26, D4).
 *
 * Built against PayPal's published REST reference. Every exchange below is a
 * recorded fixture — nothing here reaches PayPal, and there is no PayPal
 * merchant account to have run it against.
 */

beforeEach(function () {
    $settings = app(SettingsRepository::class);

    $settings->define('payment.paypal.mode', 'payment', SettingType::String, 'sandbox');
    $settings->define('payment.paypal.sandbox.client_id', 'payment', SettingType::String, 'a-client-id', isEncrypted: true);
    $settings->define('payment.paypal.sandbox.client_secret', 'payment', SettingType::String, 'a-client-secret', isEncrypted: true);
    $settings->define('payment.paypal.sandbox.webhook_id', 'payment', SettingType::String, 'WH-TEST-1', isEncrypted: true);

    $this->gateway = app(PayPalGateway::class);
});

function payPalIntent(): PaymentIntent
{
    return new PaymentIntent(
        reference: 'PAY-260901-K7M3QX9P',
        amount: Money::of(12500, Currency::USD),
        customerName: 'Nusrat Jahan',
        customerEmail: 'nusrat@example.test',
        customerMobile: '+8801712345678',
        successUrl: 'https://erp.feriwala.test/payment/success',
        failUrl: 'https://erp.feriwala.test/payment/fail',
        cancelUrl: 'https://erp.feriwala.test/payment/cancel',
        ipnUrl: 'https://erp.feriwala.test/webhook/paypal',
        description: 'Account activation',
    );
}

/**
 * The token call every other call depends on, plus one or more responses.
 */
function payPalFake(array ...$responses): void
{
    $sequence = Http::fakeSequence()->push(['access_token' => 'an-access-token', 'expires_in' => 32400]);

    foreach ($responses as $response) {
        // A token is fetched before every call, so each response needs one in
        // front of it.
        $sequence->push($response)->push(['access_token' => 'an-access-token', 'expires_in' => 32400]);
    }
}

/**
 * A captured order, as PayPal's own sample shows one.
 */
function payPalCapturedOrder(string $status = 'COMPLETED', string $captureStatus = 'COMPLETED'): array
{
    return [
        'id' => '5O190127TN364715T',
        'status' => $status,
        'purchase_units' => [[
            'invoice_id' => 'PAY-260901-K7M3QX9P',
            'payments' => [
                'captures' => [[
                    'id' => '3C679366HH908993F',
                    'status' => $captureStatus,
                    'amount' => ['currency_code' => 'USD', 'value' => '125.00'],
                ]],
            ],
        ]],
    ];
}

describe('initiating', function () {
    it('creates an order and returns its approval link', function () {
        payPalFake([
            'id' => '5O190127TN364715T',
            'status' => 'CREATED',
            'links' => [
                ['rel' => 'self', 'href' => 'https://api-m.sandbox.paypal.com/v2/checkout/orders/5O190127TN364715T'],
                ['rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=5O190127TN364715T'],
            ],
        ]);

        $redirect = $this->gateway->initiate(payPalIntent());

        expect($redirect->url)->toBe('https://www.sandbox.paypal.com/checkoutnow?token=5O190127TN364715T')
            ->and($redirect->gatewayReference)->toBe('5O190127TN364715T');

        Http::assertSent(fn ($request) => ! str_contains($request->url(), '/v2/checkout/orders')
            // PayPal takes a decimal string, unlike Stripe.
            || ($request['purchase_units'][0]['amount']['value'] === '125.00'
                && $request['purchase_units'][0]['amount']['currency_code'] === 'USD'
                && $request['purchase_units'][0]['invoice_id'] === 'PAY-260901-K7M3QX9P'
                && $request->header('PayPal-Request-Id')[0] === 'order:PAY-260901-K7M3QX9P'));
    });

    it('throws when the order carries no approval link', function () {
        payPalFake(['id' => '5O1', 'status' => 'CREATED', 'links' => []]);

        expect(fn () => $this->gateway->initiate(payPalIntent()))
            ->toThrow(GatewayUnavailable::class, 'no order was created');
    });

    it('throws when authentication is refused', function () {
        Http::fake(['*' => Http::response(['error' => 'invalid_client'], 401)]);

        expect(fn () => $this->gateway->initiate(payPalIntent()))
            ->toThrow(GatewayUnavailable::class);
    });

    it('refuses to run without configured credentials', function () {
        app(SettingsRepository::class)->set('payment.paypal.sandbox.webhook_id', '');

        Http::fake();

        expect(fn () => $this->gateway->initiate(payPalIntent()))
            ->toThrow(GatewayUnavailable::class, 'No credentials are configured');

        Http::assertNothingSent();
    });
});

describe('currency (D4)', function () {
    it('does not offer BDT, because that would need a rate nobody has', function () {
        expect($this->gateway->supportedCurrencies())->not->toContain(Currency::BDT);
    });

    it('is not among the gateways offered for a taka amount', function () {
        expect(app(PaymentGatewayManager::class)->availableFor(Currency::BDT))
            ->not->toContain('paypal');
    });
});

describe('capture is part of verification', function () {
    it('captures an approved order and reports the completed payment', function () {
        payPalFake(
            ['id' => '5O190127TN364715T', 'status' => 'APPROVED', 'purchase_units' => [['invoice_id' => 'PAY-260901-K7M3QX9P']]],
            payPalCapturedOrder(),
        );

        $result = $this->gateway->verify('5O190127TN364715T');

        expect($result->isPaid())->toBeTrue()
            ->and($result->amount->minorUnits)->toBe(12500)
            ->and($result->amount->currency)->toBe(Currency::USD)
            ->and($result->reference)->toBe('PAY-260901-K7M3QX9P')
            // The capture id, which is what a refund is addressed to.
            ->and($result->settlementReference)->toBe('3C679366HH908993F');

        Http::assertSent(fn ($request) => ! str_contains($request->url(), '/capture')
            || $request->header('PayPal-Request-Id')[0] === 'capture:5O190127TN364715T');
    });

    it('reads an already completed order rather than capturing it again', function () {
        /*
         * Capturing only from APPROVED is what makes a repeated call cheap and
         * safe. An order already captured is read.
         */
        payPalFake(payPalCapturedOrder());

        expect($this->gateway->verify('5O190127TN364715T')->isPaid())->toBeTrue();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/capture'));
    });

    it('leaves an order the payer has not approved pending', function () {
        payPalFake(['id' => '5O1', 'status' => 'CREATED', 'purchase_units' => []]);

        expect($this->gateway->verify('5O1')->outcome)->toBe(GatewayOutcome::Pending);
    });

    it('treats a capture that is not COMPLETED as not yet confirmed', function () {
        /*
         * PayPal's reference documents COMPLETED at every level without
         * enumerating the rest in one place, so this driver acts on the
         * documented success and leaves everything else pending. Pending
         * releases nothing, which is the right side to be wrong on.
         */
        payPalFake(payPalCapturedOrder(captureStatus: 'PENDING'));

        expect($this->gateway->verify('5O190127TN364715T')->outcome)->toBe(GatewayOutcome::Pending);
    });

    it('reports a PayPal error as a failure with its name', function () {
        payPalFake(['name' => 'RESOURCE_NOT_FOUND', 'message' => 'The specified resource does not exist.']);

        $result = $this->gateway->verify('5O-nothing');

        expect($result->outcome)->toBe(GatewayOutcome::Failed)
            ->and($result->errorCode)->toBe('RESOURCE_NOT_FOUND');
    });

    it('throws rather than guessing when a completed capture carries no amount', function () {
        payPalFake([
            'id' => '5O1',
            'status' => 'COMPLETED',
            'purchase_units' => [['payments' => ['captures' => [['id' => '3C6', 'status' => 'COMPLETED']]]]],
        ]);

        expect(fn () => $this->gateway->verify('5O1'))
            ->toThrow(GatewayUnavailable::class, 'could not be understood');
    });

    it('reports a return naming no order as failed', function () {
        expect($this->gateway->handleCallback(Request::create('/payment/success'))->outcome)
            ->toBe(GatewayOutcome::Failed);
    });
});

describe('webhook verification asks PayPal (§26.4)', function () {
    function payPalWebhook(array $headers = []): Request
    {
        return Request::create(
            '/webhook/paypal',
            'POST',
            server: [
                'HTTP_PAYPAL_TRANSMISSION_ID' => 'tx-1',
                'HTTP_PAYPAL_TRANSMISSION_TIME' => '2026-09-12T10:00:00Z',
                'HTTP_PAYPAL_TRANSMISSION_SIG' => 'a-signature',
                'HTTP_PAYPAL_CERT_URL' => 'https://api.sandbox.paypal.com/cert.pem',
                'HTTP_PAYPAL_AUTH_ALGO' => 'SHA256withRSA',
                'CONTENT_TYPE' => 'application/json',
                ...$headers,
            ],
            content: '{"id":"WH-1","event_type":"PAYMENT.CAPTURE.COMPLETED"}',
        );
    }

    it('believes only an explicit SUCCESS', function () {
        payPalFake(['verification_status' => 'SUCCESS']);

        expect($this->gateway->verifyWebhookSignature(payPalWebhook()))->toBeTrue();

        Http::assertSent(fn ($request) => ! str_contains($request->url(), 'verify-webhook-signature')
            || ($request['transmission_id'] === 'tx-1'
                && $request['auth_algo'] === 'SHA256withRSA'
                && $request['webhook_id'] === 'WH-TEST-1'
                // The event as it arrived, not a re-encoding of it.
                && $request['webhook_event']['id'] === 'WH-1'));
    });

    it('rejects anything that is not SUCCESS', function () {
        payPalFake(['verification_status' => 'FAILURE']);

        expect($this->gateway->verifyWebhookSignature(payPalWebhook()))->toBeFalse();
    });

    it('rejects a notification missing any transmission header', function () {
        Http::fake();

        expect($this->gateway->verifyWebhookSignature(payPalWebhook(['HTTP_PAYPAL_TRANSMISSION_SIG' => ''])))
            ->toBeFalse();

        // Nothing is even asked when the request is already incomplete.
        Http::assertNothingSent();
    });

    it('fails closed when PayPal cannot be reached to ask', function () {
        /*
         * Could not ask, so cannot conclude it is genuine. An unverifiable
         * notification is an anonymous request claiming money arrived.
         */
        Http::fake(['*' => Http::response('', 503)]);

        expect($this->gateway->verifyWebhookSignature(payPalWebhook()))->toBeFalse();
    });

    it('rejects a body that is not JSON at all', function () {
        Http::fake();

        $request = Request::create(
            '/webhook/paypal',
            'POST',
            server: [
                'HTTP_PAYPAL_TRANSMISSION_ID' => 'tx-1',
                'HTTP_PAYPAL_TRANSMISSION_TIME' => '2026-09-12T10:00:00Z',
                'HTTP_PAYPAL_TRANSMISSION_SIG' => 'sig',
                'HTTP_PAYPAL_CERT_URL' => 'https://api.sandbox.paypal.com/cert.pem',
                'HTTP_PAYPAL_AUTH_ALGO' => 'SHA256withRSA',
            ],
            content: 'not json',
        );

        expect($this->gateway->verifyWebhookSignature($request))->toBeFalse();
    });
});

describe('refunds (§26.3)', function () {
    it('refunds against the capture id, carrying our idempotency key', function () {
        payPalFake([
            'id' => '1JU08902781691411',
            'status' => 'COMPLETED',
            'amount' => ['currency_code' => 'USD', 'value' => '125.00'],
        ]);

        $result = $this->gateway->refund(new RefundIntent(
            reference: 'PAY-260901-K7M3QX9P',
            gatewayReference: '3C679366HH908993F',
            amount: Money::of(12500, Currency::USD),
            originalAmount: Money::of(12500, Currency::USD),
            reason: 'Duplicate payment',
            idempotencyKey: 'refund:PAY-260901-K7M3QX9P:1',
        ));

        expect($result->isSucceeded())->toBeTrue()
            ->and($result->gatewayRefundReference)->toBe('1JU08902781691411')
            ->and($result->amount->minorUnits)->toBe(12500);

        Http::assertSent(fn ($request) => ! str_contains($request->url(), '/refund')
            || (str_contains($request->url(), '/v2/payments/captures/3C679366HH908993F/refund')
                && $request['amount']['value'] === '125.00'
                && $request->header('PayPal-Request-Id')[0] === 'refund:PAY-260901-K7M3QX9P:1'));
    });

    it('sends a partial refund as the same call with a smaller amount', function () {
        payPalFake([
            'id' => '1JU2',
            'status' => 'COMPLETED',
            'amount' => ['currency_code' => 'USD', 'value' => '25.00'],
        ]);

        $result = $this->gateway->refund(new RefundIntent(
            reference: 'PAY-1',
            gatewayReference: '3C679366HH908993F',
            amount: Money::of(2500, Currency::USD),
            originalAmount: Money::of(12500, Currency::USD),
            reason: 'Partial',
            idempotencyKey: 'refund:PAY-1:1',
        ));

        expect($result->isSucceeded())->toBeTrue()
            ->and($result->amount->minorUnits)->toBe(2500);
    });

    it('treats anything short of COMPLETED as still in flight', function () {
        payPalFake([
            'id' => '1JU3',
            'status' => 'PENDING',
            'amount' => ['currency_code' => 'USD', 'value' => '125.00'],
        ]);

        $result = $this->gateway->refundStatus('1JU3');

        expect($result->isPending())->toBeTrue()
            ->and($result->outcome->isSettled())->toBeFalse();
    });

    it('reports a refused refund as a result rather than throwing', function () {
        payPalFake([
            'name' => 'CAPTURE_FULLY_REFUNDED',
            'message' => 'The capture has already been fully refunded.',
        ]);

        $result = $this->gateway->refundStatus('1JU4');

        expect($result->outcome)->toBe(GatewayRefundOutcome::Failed)
            ->and($result->errorCode)->toBe('CAPTURE_FULLY_REFUNDED');
    });
});

describe('shipped disabled (P2-38)', function () {
    it('is off in configuration', function () {
        expect(config('payment.gateways.paypal.enabled'))->toBeFalse();
    });

    it('cannot be switched on without a merchant account to credential it', function () {
        $this->seed(RolesAndPermissionsSeeder::class);
        $manager = testPlatformStaff(PlatformRole::PaymentManager);

        app(SettingsRepository::class)->set('payment.paypal.sandbox.client_secret', '');

        expect(fn () => app(ConfigureGateway::class)->setEnabled($manager, 'paypal', true))
            ->toThrow(RuntimeException::class, 'client secret');
    });

    it('still declares everything it can do, so the screen is honest', function () {
        expect($this->gateway->capabilities())->toContain(
            GatewayCapability::WebhookSignature,
            GatewayCapability::RefundFull,
            GatewayCapability::RefundPartial,
            GatewayCapability::RefundStatus,
        );
    });
});
