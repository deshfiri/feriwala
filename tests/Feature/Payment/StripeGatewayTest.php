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
use App\Integrations\Payment\Gateways\Stripe\StripeGateway;
use App\Integrations\Payment\PaymentGatewayManager;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/*
 * Stripe Checkout (P2-27, §26, D4).
 *
 * Built against Stripe's published API reference. Every exchange below is a
 * recorded fixture — nothing here reaches Stripe, and there is no Stripe
 * merchant account to have run it against.
 */

const STRIPE_WEBHOOK_SECRET = 'whsec_test_secret';

beforeEach(function () {
    $settings = app(SettingsRepository::class);

    $settings->define('payment.stripe.mode', 'payment', SettingType::String, 'sandbox');
    $settings->define('payment.stripe.sandbox.secret_key', 'payment', SettingType::String, 'sk_test_abc123', isEncrypted: true);
    $settings->define('payment.stripe.sandbox.webhook_secret', 'payment', SettingType::String, STRIPE_WEBHOOK_SECRET, isEncrypted: true);

    $this->gateway = app(StripeGateway::class);
});

function stripeIntent(?Money $amount = null): PaymentIntent
{
    return new PaymentIntent(
        reference: 'PAY-260901-K7M3QX9P',
        amount: $amount ?? Money::fromDecimal('125.00', Currency::USD),
        customerName: 'Nusrat Jahan',
        customerEmail: 'nusrat@example.test',
        customerMobile: '+8801712345678',
        successUrl: 'https://erp.feriwala.test/payment/success',
        failUrl: 'https://erp.feriwala.test/payment/fail',
        cancelUrl: 'https://erp.feriwala.test/payment/cancel',
        ipnUrl: 'https://erp.feriwala.test/webhook/stripe',
        description: 'Account activation',
    );
}

/**
 * Sign a payload exactly as Stripe does, so the verifier is tested against the
 * real algorithm rather than against itself.
 */
function stripeSignature(string $payload, ?int $timestamp = null, ?string $secret = null): string
{
    $timestamp ??= time();

    $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $secret ?? STRIPE_WEBHOOK_SECRET);

    return "t={$timestamp},v1={$signature}";
}

function stripeWebhook(string $payload, string $signature): Request
{
    return Request::create(
        '/webhook/stripe',
        'POST',
        server: ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
        content: $payload,
    );
}

describe('initiating', function () {
    it('sends minor units directly, because that is what Stripe wants', function () {
        /*
         * The one driver that does no decimal conversion. Stripe's unit_amount
         * is "a positive integer in the smallest currency unit", which is how
         * Money already holds everything.
         */
        Http::fake(['*' => Http::response([
            'id' => 'cs_test_abc',
            'url' => 'https://checkout.stripe.com/c/pay/cs_test_abc',
            'status' => 'open',
            'payment_status' => 'unpaid',
        ])]);

        $redirect = $this->gateway->initiate(stripeIntent());

        expect($redirect->url)->toBe('https://checkout.stripe.com/c/pay/cs_test_abc')
            ->and($redirect->gatewayReference)->toBe('cs_test_abc');

        Http::assertSent(function ($request) {
            $body = $request->body();

            return str_contains($request->url(), '/v1/checkout/sessions')
                && str_contains(urldecode($body), 'line_items[0][price_data][unit_amount]=12500')
                && str_contains(urldecode($body), 'line_items[0][price_data][currency]=usd')
                && str_contains(urldecode($body), 'client_reference_id=PAY-260901-K7M3QX9P')
                // Stripe's own replay protection on the create call.
                && $request->header('Idempotency-Key')[0] === 'session:PAY-260901-K7M3QX9P';
        });
    });

    it('throws when Stripe refuses to create a session', function () {
        Http::fake(['*' => Http::response(['error' => ['message' => 'No such price', 'code' => 'resource_missing']], 400)]);

        expect(fn () => $this->gateway->initiate(stripeIntent()))
            ->toThrow(GatewayUnavailable::class, 'No such price');
    });
});

describe('currency (D4)', function () {
    it('does not offer BDT, because that would need a rate nobody has', function () {
        /*
         * The base ledger is in taka and version 1 performs no exchange-rate
         * accounting, so a Stripe payment cannot be a conversion of a taka
         * amount. Refused before initiation rather than reconciled afterwards.
         */
        expect($this->gateway->supportedCurrencies())->not->toContain(Currency::BDT)
            ->and($this->gateway->supportedCurrencies())->toContain(Currency::USD);
    });

    it('is not among the gateways offered for a taka amount', function () {
        expect(app(PaymentGatewayManager::class)->availableFor(Currency::BDT))
            ->not->toContain('stripe');
    });
});

describe('the key must match the mode it is stored under', function () {
    it('reports a live key under sandbox as configuration that is not ready', function () {
        /*
         * Stripe has no separate sandbox host — the key decides which
         * environment a call reaches. So nothing but the key itself stops a
         * test configuration taking real money.
         */
        app(SettingsRepository::class)->set('payment.stripe.sandbox.secret_key', 'sk_live_realmoney');

        $gateway = app(StripeGateway::class);

        expect($gateway->misconfiguredMode())->toBeTrue()
            ->and($gateway->missingConfiguration())->toContain('secret_key')
            ->and($gateway->isConfigured())->toBeFalse();
    });

    it('reports a test key under live the same way', function () {
        $settings = app(SettingsRepository::class);
        $settings->set('payment.stripe.mode', 'live');
        $settings->define('payment.stripe.live.secret_key', 'payment', SettingType::String, 'sk_test_abc', isEncrypted: true);
        $settings->define('payment.stripe.live.webhook_secret', 'payment', SettingType::String, 'whsec_x', isEncrypted: true);

        expect(app(StripeGateway::class)->isConfigured())->toBeFalse();
    });

    it('accepts a test key under sandbox', function () {
        expect($this->gateway->misconfiguredMode())->toBeFalse()
            ->and($this->gateway->isConfigured())->toBeTrue();
    });
});

describe('webhook signature verification (§26.4)', function () {
    it('accepts a genuine signature over the raw body', function () {
        $payload = '{"id":"evt_1","type":"checkout.session.completed"}';

        expect($this->gateway->verifyWebhookSignature(stripeWebhook($payload, stripeSignature($payload))))
            ->toBeTrue();
    });

    it('rejects a tampered body', function () {
        // The attack this exists to stop: change the payload, keep the
        // signature.
        $signature = stripeSignature('{"id":"evt_1","amount":100}');

        expect($this->gateway->verifyWebhookSignature(
            stripeWebhook('{"id":"evt_1","amount":999999}', $signature),
        ))->toBeFalse();
    });

    it('rejects a signature made with the wrong secret', function () {
        $payload = '{"id":"evt_1"}';

        expect($this->gateway->verifyWebhookSignature(
            stripeWebhook($payload, stripeSignature($payload, secret: 'whsec_someone_elses')),
        ))->toBeFalse();
    });

    it('rejects a replayed notification once it is stale', function () {
        /*
         * The timestamp is inside the signed payload, so an attacker cannot
         * move it — which is what makes checking its age the defence against a
         * captured notification being accepted forever.
         */
        $payload = '{"id":"evt_1"}';
        $old = time() - StripeGateway::SIGNATURE_TOLERANCE - 60;

        expect($this->gateway->verifyWebhookSignature(
            stripeWebhook($payload, stripeSignature($payload, timestamp: $old)),
        ))->toBeFalse();
    });

    it('ignores the v0 scheme Stripe sends alongside test events', function () {
        /*
         * Accepting any scheme offered is a downgrade attack waiting to happen.
         * A header carrying only v0 must not verify, however valid that v0 is.
         */
        $payload = '{"id":"evt_1"}';
        $timestamp = time();
        $v0 = hash_hmac('sha256', $timestamp.'.'.$payload, STRIPE_WEBHOOK_SECRET);

        expect($this->gateway->verifyWebhookSignature(
            stripeWebhook($payload, "t={$timestamp},v0={$v0}"),
        ))->toBeFalse();
    });

    it('rejects a request with no signature header at all', function () {
        expect($this->gateway->verifyWebhookSignature(
            Request::create('/webhook/stripe', 'POST', content: '{"id":"evt_1"}'),
        ))->toBeFalse();
    });

    it('rejects a malformed header', function () {
        $payload = '{"id":"evt_1"}';

        expect($this->gateway->verifyWebhookSignature(stripeWebhook($payload, 'nonsense')))->toBeFalse()
            ->and($this->gateway->verifyWebhookSignature(stripeWebhook($payload, 't=notanumber,v1=abc')))->toBeFalse();
    });
});

describe('verification is the authoritative answer', function () {
    it('reads a paid session with its amount in minor units', function () {
        Http::fake(['*' => Http::response([
            'id' => 'cs_test_abc',
            'status' => 'complete',
            'payment_status' => 'paid',
            'amount_total' => 12500,
            'currency' => 'usd',
            'client_reference_id' => 'PAY-260901-K7M3QX9P',
            'payment_intent' => 'pi_test_999',
        ])]);

        $result = $this->gateway->verify('cs_test_abc');

        expect($result->isPaid())->toBeTrue()
            ->and($result->amount->toDecimal())->toBe('125.00')
            ->and($result->amount->currency)->toBe(Currency::USD)
            ->and($result->reference)->toBe('PAY-260901-K7M3QX9P')
            // The PaymentIntent, which is what a refund is addressed to.
            ->and($result->settlementReference)->toBe('pi_test_999');
    });

    it('reads an unpaid open session as pending', function () {
        Http::fake(['*' => Http::response([
            'id' => 'cs_1', 'status' => 'open', 'payment_status' => 'unpaid',
        ])]);

        expect($this->gateway->verify('cs_1')->outcome)->toBe(GatewayOutcome::Pending);
    });

    it('reads an expired session as failed', function () {
        Http::fake(['*' => Http::response([
            'id' => 'cs_1', 'status' => 'expired', 'payment_status' => 'unpaid',
        ])]);

        $result = $this->gateway->verify('cs_1');

        expect($result->outcome)->toBe(GatewayOutcome::Failed)
            ->and($result->errorCode)->toBe('expired');
    });

    it('throws rather than guessing when a paid session carries no amount', function () {
        Http::fake(['*' => Http::response(['id' => 'cs_1', 'payment_status' => 'paid'])]);

        expect(fn () => $this->gateway->verify('cs_1'))
            ->toThrow(GatewayUnavailable::class, 'could not be understood');
    });

    it('reports a return naming no session as failed', function () {
        expect($this->gateway->handleCallback(Request::create('/payment/success'))->outcome)
            ->toBe(GatewayOutcome::Failed);
    });
});

describe('refunds (§26.3)', function () {
    it('refunds against the payment intent, carrying our idempotency key', function () {
        Http::fake(['*' => Http::response([
            'id' => 're_test_1',
            'status' => 'succeeded',
            'amount' => 12500,
            'currency' => 'usd',
            'payment_intent' => 'pi_test_999',
        ])]);

        $result = $this->gateway->refund(new RefundIntent(
            reference: 'PAY-260901-K7M3QX9P',
            gatewayReference: 'pi_test_999',
            amount: Money::fromDecimal('125.00', Currency::USD),
            originalAmount: Money::fromDecimal('125.00', Currency::USD),
            reason: 'Duplicate payment',
            idempotencyKey: 'refund:PAY-260901-K7M3QX9P:1',
        ));

        expect($result->isSucceeded())->toBeTrue()
            ->and($result->gatewayRefundReference)->toBe('re_test_1')
            ->and($result->amount->toDecimal())->toBe('125.00');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v1/refunds')
                && str_contains(urldecode($request->body()), 'payment_intent=pi_test_999')
                && str_contains(urldecode($request->body()), 'amount=12500')
                // Stripe's own replay protection: the same key returns the
                // first refund rather than making a second one.
                && $request->header('Idempotency-Key')[0] === 'refund:PAY-260901-K7M3QX9P:1';
        });
    });

    it('treats a pending refund as accepted, not returned', function () {
        Http::fake(['*' => Http::response([
            'id' => 're_test_2', 'status' => 'pending', 'amount' => 12500, 'currency' => 'usd',
        ])]);

        $result = $this->gateway->refundStatus('re_test_2');

        expect($result->isPending())->toBeTrue()
            ->and($result->outcome->isSettled())->toBeFalse();
    });

    it('treats a failed or cancelled refund as failed', function () {
        Http::fake(['*' => Http::response([
            'id' => 're_test_3',
            'status' => 'failed',
            'failure_reason' => 'expired_or_canceled_card',
            'amount' => 12500,
            'currency' => 'usd',
        ])]);

        $result = $this->gateway->refundStatus('re_test_3');

        expect($result->outcome)->toBe(GatewayRefundOutcome::Failed)
            ->and($result->error)->toBe('expired_or_canceled_card');
    });

    it('reports a refused refund as a result rather than throwing', function () {
        Http::fake(['*' => Http::response([
            'error' => ['message' => 'Charge has already been refunded.', 'code' => 'charge_already_refunded'],
        ], 400)]);

        $result = $this->gateway->refund(new RefundIntent(
            reference: 'PAY-1',
            gatewayReference: 'pi_test_999',
            amount: Money::fromDecimal('1.00', Currency::USD),
            originalAmount: Money::fromDecimal('1.00', Currency::USD),
            reason: 'Test',
            idempotencyKey: 'refund:PAY-1:1',
        ));

        expect($result->outcome)->toBe(GatewayRefundOutcome::Failed)
            ->and($result->error)->toBe('Charge has already been refunded.')
            ->and($result->errorCode)->toBe('charge_already_refunded');
    });
});

describe('shipped disabled (P2-38)', function () {
    it('is off in configuration', function () {
        expect(config('payment.gateways.stripe.enabled'))->toBeFalse();
    });

    it('cannot be switched on without a merchant account to credential it', function () {
        $this->seed(RolesAndPermissionsSeeder::class);
        $manager = testPlatformStaff(PlatformRole::PaymentManager);

        app(SettingsRepository::class)->set('payment.stripe.sandbox.secret_key', '');

        expect(fn () => app(ConfigureGateway::class)->setEnabled($manager, 'stripe', true))
            ->toThrow(RuntimeException::class, 'secret key');
    });

    it('still declares everything it can do, so the screen is honest', function () {
        expect($this->gateway->capabilities())->toContain(
            GatewayCapability::WebhookSignature,
            GatewayCapability::RefundFull,
            GatewayCapability::RefundPartial,
            GatewayCapability::RefundStatus,
            GatewayCapability::StatusQuery,
        );
    });
});
