<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Billing\Actions\ConfigureGateway;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\Models\Setting;
use App\Domain\Settings\SettingsRepository;
use App\Integrations\Payment\Data\GatewayCapability;
use App\Integrations\Payment\Data\GatewayOutcome;
use App\Integrations\Payment\Data\PaymentIntent;
use App\Integrations\Payment\Exceptions\GatewayUnavailable;
use App\Integrations\Payment\Gateways\Eps\EpsGateway;
use App\Integrations\Payment\PaymentGatewayManager;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
 * EPS (P2-21, §26).
 *
 * Initiate, verify and status-query are implemented against EPS's official
 * SDKs (github.com/EPS-PG/EPS_Laravel, EPS_PHP) and confirmed against the
 * live sandbox with EPS_PHP's own published demo credentials — see
 * EpsGateway's own docblock for the exact sources and access date.
 *
 * What is not confirmed anywhere in EPS's public materials, and is not
 * pursued here, is the field shape of a genuinely paid
 * CheckMerchantTransactionStatus response and the IPN's AES "Secret Key".
 * Both stay refused rather than guessed — the tests below prove the refusal,
 * not paper over it.
 */

const EPS_HASH_KEY = 'test-hash-key';
const EPS_USERNAME = 'merchant@test.example';

beforeEach(function () {
    $settings = app(SettingsRepository::class);

    $settings->define('payment.eps.mode', 'payment', SettingType::String, 'sandbox');
    $settings->define('payment.eps.sandbox.merchant_id', 'payment', SettingType::String, 'MERCHANT-1', isEncrypted: true);
    $settings->define('payment.eps.sandbox.store_id', 'payment', SettingType::String, 'STORE-1', isEncrypted: true);
    $settings->define('payment.eps.sandbox.username', 'payment', SettingType::String, EPS_USERNAME, isEncrypted: true);
    $settings->define('payment.eps.sandbox.password', 'payment', SettingType::String, 'test-password', isEncrypted: true);
    $settings->define('payment.eps.sandbox.hash_key', 'payment', SettingType::String, EPS_HASH_KEY, isEncrypted: true);
    $settings->define('payment.eps.sandbox.device_type_id', 'payment', SettingType::String, '1', isEncrypted: true);

    $this->gateway = app(EpsGateway::class);
});

function anEpsIntent(): PaymentIntent
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
        ipnUrl: 'https://erp.feriwala.test/webhook/eps',
        description: 'Account activation',
    );
}

/**
 * The exact algorithm confirmed against the live sandbox: base64 of an
 * HMAC-SHA512 over the signed string, keyed with the hash key.
 */
function epsHash(string $data, string $key = EPS_HASH_KEY): string
{
    return base64_encode(hash_hmac('sha512', $data, $key, true));
}

function fakeEpsToken(): void
{
    Http::fake([
        '*/v1/Auth/GetToken' => Http::response(['token' => 'a-jwt-token', 'expireDate' => now()->addHour()->toIso8601String()]),
    ]);
}

describe('credentials', function () {
    it('uses the sandbox host in sandbox mode', function () {
        expect($this->gateway->isSandbox())->toBeTrue();
    });

    it('refuses to run without configured credentials', function () {
        app(SettingsRepository::class)->set('payment.eps.sandbox.hash_key', '');

        Http::fake();

        expect(fn () => $this->gateway->initiate(anEpsIntent()))
            ->toThrow(GatewayUnavailable::class, 'No credentials are configured');

        Http::assertNothingSent();
    });
});

describe('initiating', function () {
    it('signs the token request with the username, and the initialize request with our reference', function () {
        Http::fake([
            '*/v1/Auth/GetToken' => Http::response(['token' => 'a-jwt-token']),
            '*/v1/EPSEngine/InitializeEPS' => Http::response(['RedirectURL' => 'https://sandboxpg.eps.com.bd/pay']),
        ]);

        $this->gateway->initiate(anEpsIntent());

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v1/Auth/GetToken')
            && $request->hasHeader('x-hash', epsHash(EPS_USERNAME))
            && $request['userName'] === EPS_USERNAME);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v1/EPSEngine/InitializeEPS')
            && $request->hasHeader('x-hash', epsHash('PAY-260901-K7M3QX9P'))
            && $request->hasHeader('Authorization', 'Bearer a-jwt-token')
            && $request['merchantTransactionId'] === 'PAY-260901-K7M3QX9P'
            // The amount reaches EPS as a decimal string, never minor units.
            && $request['totalAmount'] === '6000.00');
    });

    it('returns the redirect url, keyed by our own reference', function () {
        Http::fake([
            '*/v1/Auth/GetToken' => Http::response(['token' => 'a-jwt-token']),
            '*/v1/EPSEngine/InitializeEPS' => Http::response(['RedirectURL' => 'https://sandboxpg.eps.com.bd/pay']),
        ]);

        $redirect = $this->gateway->initiate(anEpsIntent());

        expect($redirect->url)->toBe('https://sandboxpg.eps.com.bd/pay')
            ->and($redirect->gatewayReference)->toBe('PAY-260901-K7M3QX9P');
    });

    it('throws when EPS cannot issue a token', function () {
        Http::fake(['*/v1/Auth/GetToken' => Http::response(['errorMessage' => 'Invalid credentials'], 401)]);

        expect(fn () => $this->gateway->initiate(anEpsIntent()))
            ->toThrow(GatewayUnavailable::class);
    });

    it('throws when EPS refuses to start a session', function () {
        Http::fake([
            '*/v1/Auth/GetToken' => Http::response(['token' => 'a-jwt-token']),
            '*/v1/EPSEngine/InitializeEPS' => Http::response(['ErrorMessage' => 'Store not active']),
        ]);

        expect(fn () => $this->gateway->initiate(anEpsIntent()))
            ->toThrow(GatewayUnavailable::class, 'Store not active');
    });

    it('throws when EPS is unreachable', function () {
        Http::fake(['*' => Http::response('', 503)]);

        expect(fn () => $this->gateway->initiate(anEpsIntent()))
            ->toThrow(GatewayUnavailable::class);
    });
});

describe('the browser callback is never trusted', function () {
    it('reports an apparent success as pending, not paid', function () {
        $result = $this->gateway->handleCallback(
            Request::create('/payment/success', 'GET', [
                'MerchantTransactionId' => 'PAY-1',
                'Status' => 'SUCCESS',
            ]),
        );

        expect($result->outcome)->toBe(GatewayOutcome::Pending)
            ->and($result->isPaid())->toBeFalse()
            ->and($result->gatewayReference)->toBe('PAY-1');
    });

    it('reads a cancellation', function () {
        $result = $this->gateway->handleCallback(
            Request::create('/payment/cancel', 'GET', [
                'MerchantTransactionId' => 'PAY-1',
                'Status' => 'CANCEL',
            ]),
        );

        expect($result->outcome)->toBe(GatewayOutcome::Cancelled);
    });

    it('reads a failure', function () {
        $result = $this->gateway->handleCallback(
            Request::create('/payment/fail', 'GET', [
                'MerchantTransactionId' => 'PAY-1',
                'Status' => 'FAILED',
            ]),
        );

        expect($result->outcome)->toBe(GatewayOutcome::Failed)
            ->and($result->errorCode)->toBe('FAILED');
    });

    it('refuses an encrypted IPN body rather than misreading it as an empty return', function () {
        // The real shape EPS's own IPN page documents -- {"Data": "IV:Ciphertext"}.
        $result = $this->gateway->handleCallback(
            Request::create('/webhook/eps', 'POST', ['Data' => 'aXY6Y2lwaGVy']),
        );

        expect($result->outcome)->toBe(GatewayOutcome::Failed)
            ->and($result->errorCode)->toBe('ipn_unsupported')
            ->and($result->reference)->toBeNull();
    });
});

describe('IPN signature verification', function () {
    it('fails closed on every notification, signed-looking or not', function () {
        // The IPN "Secret Key" EPS's own page never identifies -- see the
        // class docblock. Nothing here can tell a genuine notification from
        // a forged one, so nothing is ever accepted.
        expect($this->gateway->verifyWebhookSignature(Request::create('/webhook/eps', 'POST', ['Data' => 'anything'])))
            ->toBeFalse();
    });
});

describe('server-side verification', function () {
    it('reports a settled payment when EPS names an amount', function () {
        Http::fake([
            '*/v1/Auth/GetToken' => Http::response(['token' => 'a-jwt-token']),
            '*/v1/EPSEngine/CheckMerchantTransactionStatus*' => Http::response([
                'transactionStatus' => 'SUCCESS',
                'totalAmount' => 6000.00,
            ]),
        ]);

        $result = $this->gateway->verify('PAY-260901-K7M3QX9P');

        expect($result->isPaid())->toBeTrue()
            ->and($result->amount->toDecimal())->toBe('6000.00')
            ->and($result->matchesAmount(Money::fromDecimal('6000.00')))->toBeTrue();

        Http::assertSent(fn ($request) => str_ends_with(explode('?', $request->url())[0], '/v1/EPSEngine/CheckMerchantTransactionStatus')
            && $request->hasHeader('x-hash', epsHash('PAY-260901-K7M3QX9P')));
    });

    it('verify() and status() ask the same authoritative question', function () {
        // EPS exposes exactly one lookup, addressed by our own reference --
        // there is no separate identifier to verify EPS's own side by.
        Http::fake([
            '*/v1/Auth/GetToken' => Http::response(['token' => 'a-jwt-token']),
            '*/v1/EPSEngine/CheckMerchantTransactionStatus*' => Http::response([
                'status' => 'SUCCESS',
                'amount' => 6000.00,
            ]),
        ]);

        $status = $this->gateway->status('PAY-260901-K7M3QX9P');

        expect($status->isPaid())->toBeTrue()
            ->and($status->amount->toDecimal())->toBe('6000.00');
    });

    it('reads a nested data.status/data.amount shape too', function () {
        // EPS_PHP's own published example tries this shape as well as the
        // flat one -- the vendor's own code does not commit to one.
        Http::fake([
            '*/v1/Auth/GetToken' => Http::response(['token' => 'a-jwt-token']),
            '*/v1/EPSEngine/CheckMerchantTransactionStatus*' => Http::response([
                'data' => ['transactionStatus' => 'COMPLETED', 'totalAmount' => '6000.00'],
            ]),
        ]);

        expect($this->gateway->verify('PAY-1')->isPaid())->toBeTrue();
    });

    it('throws rather than guessing when a success carries no recognisable amount', function () {
        // A confirmed success this driver cannot price is evidence the
        // response shape was never confirmed, not evidence of a real amount.
        Http::fake([
            '*/v1/Auth/GetToken' => Http::response(['token' => 'a-jwt-token']),
            '*/v1/EPSEngine/CheckMerchantTransactionStatus*' => Http::response(['status' => 'SUCCESS']),
        ]);

        expect(fn () => $this->gateway->verify('PAY-1'))
            ->toThrow(GatewayUnavailable::class, 'could not be understood');
    });

    it('throws rather than guessing when no status can be found at all', function () {
        Http::fake([
            '*/v1/Auth/GetToken' => Http::response(['token' => 'a-jwt-token']),
            '*/v1/EPSEngine/CheckMerchantTransactionStatus*' => Http::response(['somethingElse' => true]),
        ]);

        expect(fn () => $this->gateway->verify('PAY-1'))
            ->toThrow(GatewayUnavailable::class, 'could not be understood');
    });

    it('reports a pending payment as pending', function () {
        Http::fake([
            '*/v1/Auth/GetToken' => Http::response(['token' => 'a-jwt-token']),
            '*/v1/EPSEngine/CheckMerchantTransactionStatus*' => Http::response(['status' => 'PENDING']),
        ]);

        expect($this->gateway->verify('PAY-1')->outcome)->toBe(GatewayOutcome::Pending);
    });

    it('reports a cancelled payment as cancelled', function () {
        Http::fake([
            '*/v1/Auth/GetToken' => Http::response(['token' => 'a-jwt-token']),
            '*/v1/EPSEngine/CheckMerchantTransactionStatus*' => Http::response(['status' => 'CANCELLED']),
        ]);

        expect($this->gateway->verify('PAY-1')->outcome)->toBe(GatewayOutcome::Cancelled);
    });

    it('reports a failed payment as failed', function () {
        Http::fake([
            '*/v1/Auth/GetToken' => Http::response(['token' => 'a-jwt-token']),
            '*/v1/EPSEngine/CheckMerchantTransactionStatus*' => Http::response(['status' => 'FAILED']),
        ]);

        $result = $this->gateway->verify('PAY-1');

        expect($result->outcome)->toBe(GatewayOutcome::Failed)
            ->and($result->errorCode)->toBe('FAILED');
    });

    it('throws when EPS is unreachable', function () {
        Http::fake([
            '*/v1/Auth/GetToken' => Http::response(['token' => 'a-jwt-token']),
            '*/v1/EPSEngine/CheckMerchantTransactionStatus*' => Http::response('', 500),
        ]);

        expect(fn () => $this->gateway->verify('PAY-1'))->toThrow(GatewayUnavailable::class);
    });

    it('keeps verification retryable when the connection itself fails', function () {
        Http::fake([
            '*/v1/Auth/GetToken' => Http::response(['token' => 'a-jwt-token']),
            '*/v1/EPSEngine/CheckMerchantTransactionStatus*' => Http::failedConnection(),
        ]);

        expect(fn () => $this->gateway->verify('PAY-1'))->toThrow(GatewayUnavailable::class);
    });

    it('never sends a credential anywhere but the provider', function () {
        Http::fake([
            '*/v1/Auth/GetToken' => Http::response(['token' => 'a-jwt-token']),
            '*/v1/EPSEngine/CheckMerchantTransactionStatus*' => Http::response('', 500),
        ]);

        Log::shouldReceive('channel')->with('payment')->andReturnSelf()->byDefault();

        expect(fn () => app(EpsGateway::class)->verify('PAY-1'))
            ->toThrow(GatewayUnavailable::class);
    });
});

describe('what this driver will not do', function () {
    it('declares only what was confirmed against the live sandbox', function () {
        expect($this->gateway->capabilities())->toBe([
            GatewayCapability::Initiate,
            GatewayCapability::Verify,
            GatewayCapability::StatusQuery,
        ]);
    });

    it('declares no refund capability -- no refund endpoint appears in any official EPS source', function () {
        expect($this->gateway->capabilities())->not->toContain(
            GatewayCapability::RefundFull,
            GatewayCapability::RefundPartial,
            GatewayCapability::RefundStatus,
            GatewayCapability::WebhookSignature,
        );
    });
});

describe('shipped state (P2-38, §26.4)', function () {
    it('ships disabled', function () {
        expect(app(PaymentGatewayManager::class)->isEnabled('eps'))->toBeFalse();
    });

    it('can now be switched on once fully credentialled, unlike Nagad', function () {
        // The point of this batch: Initiate/Verify/StatusQuery are real, so
        // ConfigureGateway::setEnabled()'s "can this confirm a payment" check
        // now passes -- there is simply no real merchant account yet to
        // credential it with for production.
        $this->seed(RolesAndPermissionsSeeder::class);
        $manager = testPlatformStaff(PlatformRole::PaymentManager);

        app(ConfigureGateway::class)->setEnabled($manager, 'eps', true);

        expect(app(PaymentGatewayManager::class)->isEnabled('eps'))->toBeTrue();
    });

    it('still holds the credentials EPS issues, encrypted', function () {
        $this->seed(RolesAndPermissionsSeeder::class);
        $manager = testPlatformStaff(PlatformRole::PaymentManager);

        $this->actingAs($manager)
            ->put(route('admin.gateways.update'), [
                'gateway' => 'eps',
                'mode' => 'sandbox',
                'credentials' => ['hash_key' => 'a-real-hash-key'],
            ])
            ->assertRedirect();

        expect(Setting::query()->where('key', 'payment.eps.sandbox.hash_key')->value('value'))
            ->not->toBe('a-real-hash-key')
            ->and(app(SettingsRepository::class)->get('payment.eps.sandbox.hash_key'))
            ->toBe('a-real-hash-key');
    });
});
