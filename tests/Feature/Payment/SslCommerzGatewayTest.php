<?php

use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Integrations\Payment\Data\GatewayCapability;
use App\Integrations\Payment\Data\GatewayOutcome;
use App\Integrations\Payment\Data\GatewayRefundOutcome;
use App\Integrations\Payment\Data\PaymentIntent;
use App\Integrations\Payment\Data\RefundIntent;
use App\Integrations\Payment\Exceptions\GatewayUnavailable;
use App\Integrations\Payment\Gateways\SslCommerz\SslCommerzGateway;
use App\Support\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

const STORE_ID = 'feriwala_test';
const STORE_PASSWORD = 'test-store-password';

beforeEach(function () {
    $settings = app(SettingsRepository::class);

    $settings->define('payment.sslcommerz.mode', 'payment', SettingType::String, 'sandbox');
    $settings->define('payment.sslcommerz.sandbox.store_id', 'payment', SettingType::String, STORE_ID, isEncrypted: true);
    $settings->define('payment.sslcommerz.sandbox.store_password', 'payment', SettingType::String, STORE_PASSWORD, isEncrypted: true);

    $this->gateway = app(SslCommerzGateway::class);
});

/**
 * Where the driver talks (§26.4).
 *
 * A machine with no route to SSLCommerz can stand a stub in front of the
 * sandbox — that is what a local browser check runs against. Live mode and
 * production are never redirected anywhere, whatever is configured.
 */
describe('the gateway it talks to', function () {
    it('uses a local stub in sandbox mode outside production, and nothing else', function () {
        Http::fake(['*' => Http::response(['status' => 'SUCCESS', 'GatewayPageURL' => 'http://127.0.0.1:8004/stub/pay'])]);

        config(['payment.gateways.sslcommerz.sandbox_host' => 'http://127.0.0.1:8004/gateway']);

        $this->gateway->initiate(anIntent());

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'http://127.0.0.1:8004/gateway/gwprocess'));
    });

    it('ignores an override that is not on this machine', function () {
        Http::fake(['*' => Http::response(['status' => 'SUCCESS', 'GatewayPageURL' => 'https://sandbox.sslcommerz.com/pay'])]);

        config(['payment.gateways.sslcommerz.sandbox_host' => 'https://evil.test']);

        $this->gateway->initiate(anIntent());

        Http::assertSent(fn ($request) => str_starts_with($request->url(), SslCommerzGateway::SANDBOX_HOST));
    });

    it('ignores it in production, where the sandbox is the sandbox', function () {
        Http::fake(['*' => Http::response(['status' => 'SUCCESS', 'GatewayPageURL' => 'https://sandbox.sslcommerz.com/pay'])]);

        config(['payment.gateways.sslcommerz.sandbox_host' => 'http://127.0.0.1:8004/gateway']);
        app()['env'] = 'production';

        try {
            $this->gateway->initiate(anIntent());
        } finally {
            app()['env'] = 'testing';
        }

        Http::assertSent(fn ($request) => str_starts_with($request->url(), SslCommerzGateway::SANDBOX_HOST));
    });

    it('never leaves the live host in live mode', function () {
        Http::fake(['*' => Http::response(['status' => 'SUCCESS', 'GatewayPageURL' => 'https://securepay.sslcommerz.com/pay'])]);

        $settings = app(SettingsRepository::class);
        $settings->set('payment.sslcommerz.mode', 'live');
        $settings->define('payment.sslcommerz.live.store_id', 'payment', SettingType::String, STORE_ID, isEncrypted: true);
        $settings->define('payment.sslcommerz.live.store_password', 'payment', SettingType::String, STORE_PASSWORD, isEncrypted: true);
        config(['payment.gateways.sslcommerz.sandbox_host' => 'http://127.0.0.1:8004/gateway']);

        app(SslCommerzGateway::class)->initiate(anIntent());

        Http::assertSent(fn ($request) => str_starts_with($request->url(), SslCommerzGateway::LIVE_HOST));
    });
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

/**
 * A refund of a ৳6,000 payment, addressed the way the provider requires.
 *
 * `gatewayReference` is the **banking** id here, not the validation id: that is
 * what the documented refund call takes, and it is what the payment recorded
 * when it settled.
 */
function aRefund(?Money $amount = null): RefundIntent
{
    return new RefundIntent(
        reference: 'PAY-260901-K7M3QX9P',
        gatewayReference: 'BANK-778899',
        amount: $amount ?? Money::of(600000),
        originalAmount: Money::of(600000),
        reason: 'Duplicate payment',
        idempotencyKey: 'refund:PAY-260901-K7M3QX9P:1',
    );
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

    it('keeps the banking reference a refund will need', function () {
        /*
         * SSLCommerz validates against `val_id` but refunds against
         * `bank_tran_id`. Taking it now means a refund is one call, not a
         * lookup that can fail when somebody is already owed their money.
         */
        Http::fake([
            '*' => Http::response([
                'status' => 'VALID',
                'tran_id' => 'PAY-260901-K7M3QX9P',
                'currency_amount' => '6000.00',
                'currency_type' => 'BDT',
                'bank_tran_id' => 'BANK-778899',
            ]),
        ]);

        expect($this->gateway->verify('VAL123456')->settlementReference)->toBe('BANK-778899');
    });
});

describe('status lookup (§28.1)', function () {
    it('asks about our own transaction id, not the gateway one', function () {
        // What reconciliation has to work with. A payer who closed the tab
        // never sent a val_id back, so this is the only question left to ask.
        Http::fake([
            '*' => Http::response([
                'APIConnect' => 'DONE',
                'no_of_trans_found' => 1,
                'element' => [[
                    'status' => 'VALID',
                    'tran_id' => 'PAY-260901-K7M3QX9P',
                    'val_id' => 'VAL123456',
                    'currency_amount' => '6000.00',
                    'currency_type' => 'BDT',
                    'bank_tran_id' => 'BANK-778899',
                ]],
            ]),
        ]);

        $result = $this->gateway->status('PAY-260901-K7M3QX9P');

        expect($result->isPaid())->toBeTrue()
            ->and($result->gatewayReference)->toBe('VAL123456')
            ->and($result->settlementReference)->toBe('BANK-778899');

        Http::assertSent(fn ($request) => $request['tran_id'] === 'PAY-260901-K7M3QX9P');
    });

    it('finds the attempt that succeeded, not the last one tried', function () {
        /*
         * SSLCommerz lets one transaction id be attempted more than once, and
         * does not document the order of the array. Reading the last element
         * would report a paid transaction as failed because the payer pressed
         * the button again after it had already gone through.
         */
        Http::fake([
            '*' => Http::response([
                'APIConnect' => 'DONE',
                'no_of_trans_found' => 2,
                'element' => [
                    [
                        'status' => 'VALID',
                        'val_id' => 'VAL-GOOD',
                        'currency_amount' => '6000.00',
                        'currency_type' => 'BDT',
                    ],
                    ['status' => 'FAILED', 'val_id' => 'VAL-BAD'],
                ],
            ]),
        ]);

        $result = $this->gateway->status('PAY-260901-K7M3QX9P');

        expect($result->isPaid())->toBeTrue()
            ->and($result->gatewayReference)->toBe('VAL-GOOD');
    });

    it('reports a transaction the gateway has never heard of', function () {
        Http::fake([
            '*' => Http::response(['APIConnect' => 'DONE', 'no_of_trans_found' => 0, 'element' => []]),
        ]);

        $result = $this->gateway->status('PAY-NOTHING');

        expect($result->outcome)->toBe(GatewayOutcome::Failed)
            ->and($result->errorCode)->toBe('not_found');
    });

    it('throws rather than concluding anything when the merchant API refuses', function () {
        /*
         * "We could not ask" is not "the payment failed". A bad credential or
         * an inactive merchant account must never be read as an answer about
         * somebody's money.
         */
        Http::fake(['*' => Http::response(['APIConnect' => 'INACTIVE'])]);

        expect(fn () => $this->gateway->status('PAY-1'))
            ->toThrow(GatewayUnavailable::class, 'INACTIVE');
    });
});

describe('refunds (§26.3)', function () {
    it('refunds against the banking reference, carrying our idempotency key', function () {
        Http::fake([
            '*' => Http::response([
                'APIConnect' => 'DONE',
                'status' => 'success',
                'refund_ref_id' => 'REF-001',
            ]),
        ]);

        $this->gateway->refund(aRefund());

        Http::assertSent(fn ($request) => $request['bank_tran_id'] === 'BANK-778899'
            // Sent as the provider's own unique refund id: the same key twice
            // is the same instruction, not a second one.
            && $request['refund_trans_id'] === 'refund:PAY-260901-K7M3QX9P:1'
            && $request['refund_amount'] === '6000.00'
            && $request['refund_remarks'] === 'Duplicate payment');
    });

    it('treats an accepted refund as pending, never as money returned', function () {
        /*
         * The provider's own vocabulary separates acceptance from settlement.
         * Reversing our ledger on `success` would give money back that has not
         * yet left theirs.
         */
        Http::fake([
            '*' => Http::response([
                'APIConnect' => 'DONE',
                'status' => 'success',
                'refund_ref_id' => 'REF-001',
            ]),
        ]);

        $result = $this->gateway->refund(aRefund());

        expect($result->isPending())->toBeTrue()
            ->and($result->isSucceeded())->toBeFalse()
            ->and($result->outcome->isSettled())->toBeFalse()
            ->and($result->gatewayRefundReference)->toBe('REF-001');
    });

    it('sends a partial refund as the same call with a smaller amount', function () {
        Http::fake([
            '*' => Http::response([
                'APIConnect' => 'DONE',
                'status' => 'processing',
                'refund_ref_id' => 'REF-002',
            ]),
        ]);

        $result = $this->gateway->refund(aRefund(Money::of(150000)));

        expect($result->isPending())->toBeTrue();

        Http::assertSent(fn ($request) => $request['refund_amount'] === '1500.00');
    });

    it('refuses to hold a refund it can never ask about again', function () {
        // The refund query takes `refund_ref_id` and nothing else. Accepted
        // without one is an instruction that can never be followed up.
        Http::fake(['*' => Http::response(['APIConnect' => 'DONE', 'status' => 'success'])]);

        expect(fn () => $this->gateway->refund(aRefund()))
            ->toThrow(GatewayUnavailable::class, 'could not be understood');
    });

    it('reports a refused refund as a result rather than throwing', function () {
        Http::fake([
            '*' => Http::response([
                'APIConnect' => 'DONE',
                'status' => 'failed',
                'errorReason' => 'Refund window has closed',
            ]),
        ]);

        $result = $this->gateway->refund(aRefund());

        expect($result->outcome)->toBe(GatewayRefundOutcome::Failed)
            ->and($result->error)->toBe('Refund window has closed');
    });
});

describe('refund status', function () {
    it('treats only refunded as money actually returned', function () {
        Http::fake([
            '*' => Http::response([
                'APIConnect' => 'DONE',
                'status' => 'refunded',
                'refunded_on' => '2026-09-12 11:00:00',
            ]),
        ]);

        $result = $this->gateway->refundStatus('REF-001');

        expect($result->isSucceeded())->toBeTrue()
            ->and($result->outcome->isSettled())->toBeTrue()
            // The provider does not restate the amount, and inventing one here
            // would read as its confirmation of a figure it never gave.
            ->and($result->amount)->toBeNull();

        Http::assertSent(fn ($request) => $request['refund_ref_id'] === 'REF-001');
    });

    it('keeps a refund still in flight out of the ledger', function () {
        Http::fake(['*' => Http::response(['APIConnect' => 'DONE', 'status' => 'processing'])]);

        $result = $this->gateway->refundStatus('REF-001');

        expect($result->isPending())->toBeTrue()
            ->and($result->outcome->isSettled())->toBeFalse();
    });

    it('reports a cancelled refund as failed', function () {
        Http::fake(['*' => Http::response(['APIConnect' => 'DONE', 'status' => 'cancelled'])]);

        expect($this->gateway->refundStatus('REF-001')->outcome)
            ->toBe(GatewayRefundOutcome::Failed);
    });
});

describe('what this driver will not do', function () {
    it('declares refunding and status lookup, having been built against the v4 API', function () {
        expect($this->gateway->capabilities())->toContain(
            GatewayCapability::StatusQuery,
            GatewayCapability::RefundFull,
            GatewayCapability::RefundPartial,
            GatewayCapability::RefundStatus,
        );
    });

    it('never sends a credential anywhere but the provider', function () {
        /*
         * The store password is a query parameter on every one of these calls.
         * What must never happen is it reaching a log line on the way past
         * (§42).
         */
        Http::fake(['*' => Http::response(['APIConnect' => 'INACTIVE'])]);

        Log::shouldReceive('channel')->with('payment')->andReturnSelf();
        Log::shouldReceive('warning')->once()->withArgs(
            fn (string $message, array $context) => ! str_contains((string) json_encode($context), STORE_PASSWORD),
        );

        // Resolved after the facade is swapped: the driver holds its logger by
        // constructor injection, so one built earlier would keep the real one.
        expect(fn () => app(SslCommerzGateway::class)->status('PAY-1'))
            ->toThrow(GatewayUnavailable::class);
    });
});

it('keeps verification retryable when SSLCommerz cannot be reached', function () {
    Http::fake(['*' => Http::failedConnection()]);
    expect(fn () => $this->gateway->verify('VAL1'))->toThrow(GatewayUnavailable::class);
});

it('does not turn an unreadable validation response into a failed payment', function () {
    Http::fake(['*' => Http::response('<html>Service unavailable</html>', 200)]);
    expect(fn () => $this->gateway->verify('VAL1'))->toThrow(GatewayUnavailable::class);
});
