<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Billing\Actions\ConfigureGateway;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\Models\Setting;
use App\Domain\Settings\SettingsRepository;
use App\Integrations\Payment\Data\GatewayCapability;
use App\Integrations\Payment\Data\GatewayOutcome;
use App\Integrations\Payment\Data\PayerAddress;
use App\Integrations\Payment\Data\PaymentIntent;
use App\Integrations\Payment\Exceptions\GatewayUnavailable;
use App\Integrations\Payment\Gateways\Eps\EpsGateway;
use App\Integrations\Payment\PaymentGatewayManager;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Log\LogManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/*
 * EPS (P2-21, §26), against the EPS Merchant API Integration Guide v0.5.
 *
 * Everything the guide documents is exercised: the HMAC-SHA512 `x-hash`, the
 * token, the three endpoints on one host, the required InitializeEPS fields and
 * the PascalCase responses. What the guide does not document — a sandbox host,
 * an IPN — stays refused rather than guessed.
 */

const EPS_HASH_KEY = 'test-hash-key';
const EPS_USERNAME = 'merchant@test.example';
const EPS_BASE = 'https://pgapi.eps.com.bd';
const EPS_TXN = '2610131200001234';

beforeEach(function () {
    $settings = app(SettingsRepository::class);

    $settings->define('payment.eps.mode', 'payment', SettingType::String, 'live');
    $settings->define('payment.eps.live.base_url', 'payment', SettingType::String, EPS_BASE, isEncrypted: true);
    $settings->define('payment.eps.live.merchant_id', 'payment', SettingType::String, 'MERCHANT-1', isEncrypted: true);
    $settings->define('payment.eps.live.store_id', 'payment', SettingType::String, 'STORE-1', isEncrypted: true);
    $settings->define('payment.eps.live.username', 'payment', SettingType::String, EPS_USERNAME, isEncrypted: true);
    $settings->define('payment.eps.live.password', 'payment', SettingType::String, 'test-password', isEncrypted: true);
    $settings->define('payment.eps.live.hash_key', 'payment', SettingType::String, EPS_HASH_KEY, isEncrypted: true);

});

/**
 * A clean HTTP fake. Http::fake() only ever adds stubs, and the first match
 * wins, so a test that needs different answers starts from nothing.
 *
 * @param  array<string, mixed>  $stubs
 */
function epsFake(array $stubs = []): void
{
    Http::swap(new HttpFactory);
    app()->forgetInstance(EpsGateway::class);
    Http::fake($stubs);
}

function epsGateway(): EpsGateway
{
    return app(EpsGateway::class);
}
function anEpsIntent(bool $withAddress = true): PaymentIntent
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
        payerAddress: $withAddress ? new PayerAddress('House 1, Road 2', 'Dhaka', 'Dhaka', '1207', 'BD') : null,
        attemptReference: EPS_TXN,
        ipAddress: '103.12.45.69',
    );
}

/**
 * The guide's algorithm: base64 of an HMAC-SHA512 over the signed string,
 * keyed with the hash key.
 */
function epsHash(string $data, string $key = EPS_HASH_KEY): string
{
    return base64_encode(hash_hmac('sha512', $data, $key, true));
}

/** A JWT whose only meaningful claim is its expiry, as GetToken returns. */
function epsJwt(int $expiresAt, string $marker = 'a'): string
{
    $encode = fn (array $part) => rtrim(strtr(base64_encode((string) json_encode($part)), '+/', '-_'), '=');

    return $encode(['alg' => 'HS256']).'.'.$encode(['exp' => $expiresAt, 'm' => $marker]).'.signature';
}

function epsTokenResponse(?int $expiresAt = null, string $marker = 'a')
{
    $expiresAt ??= time() + 3600;

    return Http::response([
        'token' => epsJwt($expiresAt, $marker),
        'expireDate' => date('Y-m-d\TH:i:s', $expiresAt + 6 * 3600).'.0000000',
        'errorMessage' => null,
        'errorCode' => null,
    ]);
}

function epsStatusResponse(array $overrides = [])
{
    return Http::response([
        'MerchantTransactionId' => EPS_TXN,
        'EpsTransactionId' => 'C2549190401',
        'Status' => 'Success',
        'TotalAmount' => '6000.00',
        'TransactionDate' => '24 Jun 2024 06:06:19 PM',
        'FinancialEntity' => 'OKWallet',
        'ErrorCode' => '',
        'ErrorMessage' => '',
        'CustomerName' => 'Nusrat Jahan',
        'CustomerPhone' => '01712345678',
        ...$overrides,
    ]);
}

function epsSent(string $path, ?string $method = null): Collection
{
    return Http::recorded(fn (ClientRequest $request) => str_ends_with(explode('?', $request->url())[0], $path)
        && ($method === null || $request->method() === $method))->map(fn (array $pair) => $pair[0]);
}

describe('endpoints', function () {
    it('calls exactly the three documented paths on the stored origin', function () {
        epsFake([
            '*/v1/Auth/GetToken' => epsTokenResponse(),
            '*/v1/EPSEngine/InitializeEPS' => Http::response(['TransactionId' => 'abc', 'RedirectURL' => 'https://pg.eps.com.bd/PG?data=abc']),
            '*/v1/EPSEngine/CheckMerchantTransactionStatus*' => epsStatusResponse(),
        ]);

        epsGateway()->initiate(anEpsIntent());
        epsGateway()->verify(EPS_TXN);

        $urls = Http::recorded()->map(fn (array $pair) => explode('?', $pair[0]->url())[0])->unique()->values()->all();

        expect($urls)->toBe([
            EPS_BASE.'/v1/Auth/GetToken',
            EPS_BASE.'/v1/EPSEngine/InitializeEPS',
            EPS_BASE.'/v1/EPSEngine/CheckMerchantTransactionStatus',
        ]);
    });

    it('verifies by GET with the merchantTransactionId as a query parameter', function () {
        epsFake([
            '*/v1/Auth/GetToken' => epsTokenResponse(),
            '*/v1/EPSEngine/CheckMerchantTransactionStatus*' => epsStatusResponse(),
        ]);

        epsGateway()->verify(EPS_TXN);

        expect(epsSent('/v1/EPSEngine/CheckMerchantTransactionStatus', 'GET')->first()->url())
            ->toBe(EPS_BASE.'/v1/EPSEngine/CheckMerchantTransactionStatus?merchantTransactionId='.EPS_TXN);
    });

    it('refuses a base url that is not an official EPS origin, and never calls out', function () {
        epsFake();

        foreach (['https://evil.example.com', 'http://pgapi.eps.com.bd', 'https://pgapi.eps.com.bd/api/v1', 'https://pgapi.eps.com.bd/v1', 'https://pgapi.eps.com.bd:8443'] as $bad) {
            app(SettingsRepository::class)->set('payment.eps.live.base_url', $bad);

            expect(fn () => app(EpsGateway::class)->verify(EPS_TXN))->toThrow(GatewayUnavailable::class, 'live base URL');
        }

        Http::assertNothingSent();
    });

    it('strips a trailing slash rather than producing a double slash', function () {
        app(SettingsRepository::class)->set('payment.eps.live.base_url', EPS_BASE.'/');
        epsFake([
            '*/v1/Auth/GetToken' => epsTokenResponse(),
            '*/v1/EPSEngine/CheckMerchantTransactionStatus*' => epsStatusResponse(),
        ]);

        app(EpsGateway::class)->verify(EPS_TXN);

        expect(epsSent('/v1/Auth/GetToken')->first()->url())->toBe(EPS_BASE.'/v1/Auth/GetToken');
    });
});

describe('sandbox', function () {
    it('has no endpoint of its own — it is unconfigured until one is explicitly stored', function () {
        $settings = app(SettingsRepository::class);
        $settings->set('payment.eps.mode', 'sandbox');

        epsFake();

        $gateway = app(EpsGateway::class);

        expect($gateway->isSandbox())->toBeTrue()
            ->and($gateway->isConfigured())->toBeFalse()
            ->and($gateway->missingConfiguration())->toContain('base_url');

        expect(fn () => $gateway->verify(EPS_TXN))->toThrow(GatewayUnavailable::class, 'No credentials are configured');

        Http::assertNothingSent();
    });

    it('accepts an explicitly stored EPS sandbox origin but nothing outside eps.com.bd', function () {
        $settings = app(SettingsRepository::class);
        $settings->set('payment.eps.mode', 'sandbox');

        foreach (['base_url' => 'https://sandboxpgapi.eps.com.bd', 'merchant_id' => 'M', 'store_id' => 'S', 'username' => 'u', 'password' => 'p', 'hash_key' => 'k'] as $key => $value) {
            $settings->define("payment.eps.sandbox.{$key}", 'payment', SettingType::String, $value, isEncrypted: true);
        }

        epsFake([
            '*/v1/Auth/GetToken' => epsTokenResponse(),
            '*/v1/EPSEngine/CheckMerchantTransactionStatus*' => epsStatusResponse(),
        ]);

        app(EpsGateway::class)->verify(EPS_TXN);

        expect(epsSent('/v1/Auth/GetToken')->first()->url())->toBe('https://sandboxpgapi.eps.com.bd/v1/Auth/GetToken');

        $settings->set('payment.eps.sandbox.base_url', 'https://evil.example.com');

        expect(fn () => app(EpsGateway::class)->verify(EPS_TXN))->toThrow(GatewayUnavailable::class, 'sandbox base URL');
    });
});

describe('token and hashing', function () {
    it('signs GetToken with the username only, as base64 HMAC-SHA512, and sends the credentials as JSON', function () {
        epsFake(['*/v1/Auth/GetToken' => epsTokenResponse()]);

        epsGateway()->testConnection();

        $request = epsSent('/v1/Auth/GetToken', 'POST')->first();

        expect($request->header('x-hash'))->toBe([epsHash(EPS_USERNAME)])
            ->and($request->header('x-hash')[0])->not->toBe(EPS_HASH_KEY)
            ->and($request->header('Content-Type')[0])->toContain('application/json')
            ->and($request->data())->toBe(['userName' => EPS_USERNAME, 'password' => 'test-password']);
    });

    it('signs InitializeEPS with the merchantTransactionId and a bearer token', function () {
        $expiresAt = time() + 3600;

        epsFake([
            '*/v1/Auth/GetToken' => epsTokenResponse(expiresAt: $expiresAt, marker: 'tok'),
            '*/v1/EPSEngine/InitializeEPS' => Http::response(['TransactionId' => 'abc', 'RedirectURL' => 'https://pg.eps.com.bd/PG?data=abc']),
        ]);

        epsGateway()->initiate(anEpsIntent());

        $request = epsSent('/v1/EPSEngine/InitializeEPS')->first();

        expect($request->header('x-hash'))->toBe([epsHash(EPS_TXN)])
            ->and($request->header('Authorization'))->toBe(['Bearer '.epsJwt($expiresAt, 'tok')]);
    });

    it('signs the verification with the merchantTransactionId', function () {
        epsFake([
            '*/v1/Auth/GetToken' => epsTokenResponse(),
            '*/v1/EPSEngine/CheckMerchantTransactionStatus*' => epsStatusResponse(),
        ]);

        epsGateway()->verify(EPS_TXN);

        $request = epsSent('/v1/EPSEngine/CheckMerchantTransactionStatus')->first();

        expect($request->header('x-hash'))->toBe([epsHash(EPS_TXN)])
            ->and($request->header('Authorization')[0])->toStartWith('Bearer ey');
    });

    it('caches the token, so many requests ask for one', function () {
        epsFake([
            '*/v1/Auth/GetToken' => epsTokenResponse(),
            '*/v1/EPSEngine/CheckMerchantTransactionStatus*' => epsStatusResponse(),
        ]);

        epsGateway()->verify(EPS_TXN);
        epsGateway()->verify(EPS_TXN);
        app(EpsGateway::class)->verify(EPS_TXN);

        expect(epsSent('/v1/Auth/GetToken'))->toHaveCount(1);
    });

    it('stores the token encrypted, and never under the username', function () {
        epsFake(['*/v1/Auth/GetToken' => epsTokenResponse()]);

        epsGateway()->testConnection();

        $key = 'eps:token:'.hash('sha256', implode('|', ['live', EPS_BASE, EPS_USERNAME]));
        $stored = Cache::get($key);

        expect($stored)->toBeString()
            ->and($stored)->not->toContain('.')
            ->and(Crypt::decryptString($stored))->toStartWith('ey')
            ->and($key)->not->toContain('merchant@test');
    });

    it('does not keep a token that has expired or is about to', function () {
        epsFake([
            '*/v1/Auth/GetToken' => epsTokenResponse(expiresAt: time() + 30),
            '*/v1/EPSEngine/CheckMerchantTransactionStatus*' => epsStatusResponse(),
        ]);

        epsGateway()->verify(EPS_TXN);
        epsGateway()->verify(EPS_TXN);

        expect(epsSent('/v1/Auth/GetToken'))->toHaveCount(2);
    });

    it('asks again once the cached token has aged out', function () {
        epsFake([
            '*/v1/Auth/GetToken' => epsTokenResponse(),
            '*/v1/EPSEngine/CheckMerchantTransactionStatus*' => epsStatusResponse(),
        ]);

        epsGateway()->verify(EPS_TXN);

        Cache::forget('eps:token:'.hash('sha256', implode('|', ['live', EPS_BASE, EPS_USERNAME])));

        epsGateway()->verify(EPS_TXN);

        expect(epsSent('/v1/Auth/GetToken'))->toHaveCount(2);
    });

    it('drops a token EPS rejects and retries once with a fresh one', function () {
        $statusCalls = 0;

        epsFake([
            '*/v1/Auth/GetToken' => Http::sequence()
                ->push(['token' => epsJwt(time() + 3600, 'first')])
                ->push(['token' => epsJwt(time() + 3600, 'second')]),
            '*/v1/EPSEngine/CheckMerchantTransactionStatus*' => function () use (&$statusCalls) {
                return ++$statusCalls === 1 ? Http::response('', 401) : epsStatusResponse();
            },
        ]);

        $result = epsGateway()->verify(EPS_TXN);

        expect($result->isPaid())->toBeTrue()
            ->and(epsSent('/v1/Auth/GetToken'))->toHaveCount(2)
            ->and(epsSent('/v1/EPSEngine/CheckMerchantTransactionStatus'))->toHaveCount(2);
    });

    it('uses a token another worker has just refreshed instead of asking EPS again', function () {
        $key = 'eps:token:'.hash('sha256', implode('|', ['live', EPS_BASE, EPS_USERNAME]));
        $statusCalls = 0;

        epsFake([
            '*/v1/Auth/GetToken' => epsTokenResponse(marker: 'first'),
            '*/v1/EPSEngine/CheckMerchantTransactionStatus*' => function () use (&$statusCalls, $key) {
                if (++$statusCalls === 1) {
                    // While this request was in flight, somebody else refreshed.
                    Cache::put($key, Crypt::encryptString(epsJwt(time() + 3600, 'refreshed-elsewhere')), 600);

                    return Http::response('', 401);
                }

                return epsStatusResponse();
            },
        ]);

        epsGateway()->verify(EPS_TXN);

        expect(epsSent('/v1/Auth/GetToken'))->toHaveCount(1);

        $retry = epsSent('/v1/EPSEngine/CheckMerchantTransactionStatus')->last();

        expect($retry->header('Authorization')[0])->toBe('Bearer '.epsJwt(time() + 3600, 'refreshed-elsewhere'));
    });

    it('reports EPS\'s own message when it refuses the credentials, never the token or hash', function () {
        epsFake(['*/v1/Auth/GetToken' => Http::response(['token' => null, 'errorMessage' => 'Invalid user', 'errorCode' => 'E01'], 200)]);

        try {
            epsGateway()->testConnection();
            $this->fail('expected a refusal');
        } catch (GatewayUnavailable $e) {
            expect($e->getMessage())->toContain('Invalid user')
                ->and($e->getMessage())->not->toContain(EPS_HASH_KEY)
                ->and($e->getMessage())->not->toContain('test-password');
        }
    });

    it('can test the connection and reports success without returning the token', function () {
        epsFake(['*/v1/Auth/GetToken' => epsTokenResponse()]);

        expect(epsGateway()->testConnection())->toBeNull();
    });
});

describe('initiating', function () {
    beforeEach(function () {
        epsFake([
            '*/v1/Auth/GetToken' => epsTokenResponse(),
            '*/v1/EPSEngine/InitializeEPS' => Http::response(['TransactionId' => 'abc-123', 'RedirectURL' => 'https://pg.eps.com.bd/PG?data=abc-123', 'ErrorMessage' => '', 'ErrorCode' => null]),
        ]);
    });

    it('sends every field the guide marks mandatory, with transactionTypeId and never deviceTypeId', function () {
        epsGateway()->initiate(anEpsIntent());

        $body = epsSent('/v1/EPSEngine/InitializeEPS')->first()->data();

        expect($body)->toMatchArray([
            'merchantId' => 'MERCHANT-1',
            'storeId' => 'STORE-1',
            'CustomerOrderId' => 'PAY-260901-K7M3QX9P',
            'merchantTransactionId' => EPS_TXN,
            'transactionTypeId' => 1,
            'ipAddress' => '103.12.45.69',
            'version' => '1',
            'successUrl' => 'https://erp.feriwala.test/payment/success',
            'failUrl' => 'https://erp.feriwala.test/payment/fail',
            'cancelUrl' => 'https://erp.feriwala.test/payment/cancel',
            'customerName' => 'Nusrat Jahan',
            'customerEmail' => 'nusrat@example.test',
            'CustomerAddress' => 'House 1, Road 2',
            'CustomerCity' => 'Dhaka',
            'CustomerState' => 'Dhaka',
            'CustomerPostcode' => '1207',
            'CustomerCountry' => 'BD',
            'CustomerPhone' => '01712345678',
            'ProductName' => 'Account activation',
        ])->and($body)->not->toHaveKey('deviceTypeId');
    });

    it('sends the amount as an exact decimal number, never a float or minor units', function () {
        epsGateway()->initiate(anEpsIntent());

        expect(epsSent('/v1/EPSEngine/InitializeEPS')->first()->body())->toContain('"totalAmount":6000.00,');
    });

    it('returns the hosted page and saves nothing it was not told about', function () {
        $redirect = epsGateway()->initiate(anEpsIntent());

        expect($redirect->url)->toBe('https://pg.eps.com.bd/PG?data=abc-123')
            ->and($redirect->gatewayReference)->toBe(EPS_TXN);
    });

    it('refuses to contact EPS without a payer address', function () {
        epsFake();

        expect(fn () => epsGateway()->initiate(anEpsIntent(withAddress: false)))
            ->toThrow(GatewayUnavailable::class, 'postal address');

        Http::assertNothingSent();
    });

    it('refuses without a prepared numeric transaction id of at least ten digits', function () {
        epsFake();

        foreach ([null, '12345', 'PAY-260901-K7M3QX9P'] as $bad) {
            $intent = anEpsIntent();
            $intent = new PaymentIntent(...[...get_object_vars($intent), 'attemptReference' => $bad]);

            expect(fn () => epsGateway()->initiate($intent))->toThrow(GatewayUnavailable::class);
        }

        Http::assertNothingSent();
    });

    it('refuses a payer with no mobile number rather than inventing one', function () {
        epsFake();

        $intent = new PaymentIntent(...[...get_object_vars(anEpsIntent()), 'customerMobile' => null]);

        expect(fn () => epsGateway()->initiate($intent))->toThrow(GatewayUnavailable::class, 'mobile number');

        Http::assertNothingSent();
    });

    it('requires a transaction id and a redirect url in the answer', function () {
        epsFake([
            '*/v1/Auth/GetToken' => epsTokenResponse(),
            '*/v1/EPSEngine/InitializeEPS' => Http::response(['TransactionId' => '', 'RedirectURL' => 'https://pg.eps.com.bd/PG']),
        ]);

        expect(fn () => epsGateway()->initiate(anEpsIntent()))->toThrow(GatewayUnavailable::class, 'could not be understood');

        epsFake([
            '*/v1/Auth/GetToken' => epsTokenResponse(),
            '*/v1/EPSEngine/InitializeEPS' => Http::response(['TransactionId' => 'abc', 'RedirectURL' => '']),
        ]);

        expect(fn () => epsGateway()->initiate(anEpsIntent()))->toThrow(GatewayUnavailable::class, 'could not be understood');
    });

    it('only sends the payer to an https page EPS hosts', function () {
        foreach (['http://pg.eps.com.bd/PG', 'https://evil.example.com/PG', 'https://eps.com.bd.evil.example/PG', 'https://user:pw@pg.eps.com.bd/PG', 'javascript:alert(1)'] as $bad) {
            epsFake([
                '*/v1/Auth/GetToken' => epsTokenResponse(),
                '*/v1/EPSEngine/InitializeEPS' => Http::response(['TransactionId' => 'abc', 'RedirectURL' => $bad]),
            ]);

            expect(fn () => app(EpsGateway::class)->initiate(anEpsIntent()))->toThrow(GatewayUnavailable::class);
        }
    });

    it('surfaces EPS\'s error message and code, not the payload', function () {
        epsFake([
            '*/v1/Auth/GetToken' => epsTokenResponse(),
            '*/v1/EPSEngine/InitializeEPS' => Http::response(['TransactionId' => null, 'ErrorMessage' => 'Store not active', 'ErrorCode' => 'S12', 'CustomerPhone' => '01712345678']),
        ]);

        try {
            epsGateway()->initiate(anEpsIntent());
            $this->fail('expected a refusal');
        } catch (GatewayUnavailable $e) {
            expect($e->getMessage())->toContain('Store not active')->and($e->getMessage())->not->toContain('01712345678');
        }
    });

    it('reports a 404 with EPS\'s own message', function () {
        epsFake([
            '*/v1/Auth/GetToken' => epsTokenResponse(),
            '*/v1/EPSEngine/InitializeEPS' => Http::response(['ErrorMessage' => 'Store not found'], 404),
        ]);

        expect(fn () => epsGateway()->initiate(anEpsIntent()))->toThrow(GatewayUnavailable::class, 'HTTP 404: Store not found');
    });

    it('names an EPS access-denied redirect instead of following it to a bare 404', function () {
        epsFake([
            '*/v1/Auth/GetToken' => epsTokenResponse(),
            '*/v1/EPSEngine/InitializeEPS' => Http::response('', 302, ['Location' => 'http://pgapi.eps.com.bd/Identity/Account/AccessDenied?ReturnUrl=%2Fv1%2FEPSEngine%2FInitializeEPS']),
            '*/Identity/Account/AccessDenied*' => Http::response('', 404),
        ]);

        expect(fn () => epsGateway()->initiate(anEpsIntent()))
            ->toThrow(GatewayUnavailable::class, 'denied this merchant account access to the payment API');

        expect(epsSent('/Identity/Account/AccessDenied'))->toHaveCount(0);
    });
    it('throws when EPS cannot be reached', function () {
        epsFake(['*' => Http::failedConnection()]);

        expect(fn () => epsGateway()->initiate(anEpsIntent()))->toThrow(GatewayUnavailable::class, 'connection failed');
    });

    it('logs where it went and how it ended, and nothing sensitive', function () {
        $lines = [];

        $logger = Mockery::mock(LogManager::class);
        $logger->shouldReceive('channel')->with('payment')->andReturnSelf();
        $logger->shouldReceive('info')->andReturnUsing(function (string $message, array $context = []) use (&$lines) {
            $lines[] = $context;
        });
        $logger->shouldIgnoreMissing();

        app()->instance(LogManager::class, $logger);
        app()->forgetInstance(EpsGateway::class);
        app(EpsGateway::class)->initiate(anEpsIntent());

        $logged = json_encode($lines, JSON_UNESCAPED_SLASHES);

        expect($lines)->not->toBeEmpty()
            ->and($logged)->toContain('pgapi.eps.com.bd')->toContain('/v1/EPSEngine/InitializeEPS')
            ->and($logged)->not->toContain(EPS_HASH_KEY)->not->toContain('test-password')->not->toContain(EPS_USERNAME)
            ->not->toContain('Bearer')->not->toContain('x-hash')->not->toContain('Nusrat')->not->toContain('01712345678')
            ->not->toContain(epsHash(EPS_TXN))->not->toContain('eyJ');
    });
});

describe('server-side verification', function () {
    it('reads the documented PascalCase shape and prices it exactly', function () {
        epsFake([
            '*/v1/Auth/GetToken' => epsTokenResponse(),
            '*/v1/EPSEngine/CheckMerchantTransactionStatus*' => epsStatusResponse(),
        ]);

        $result = epsGateway()->verify(EPS_TXN);

        expect($result->isPaid())->toBeTrue()
            ->and($result->amount->toDecimal())->toBe('6000.00')
            ->and($result->matchesAmount(Money::fromDecimal('6000.00')))->toBeTrue()
            ->and($result->matchesAmount(Money::fromDecimal('5999.99')))->toBeFalse()
            ->and($result->reference)->toBe(EPS_TXN)
            ->and($result->gatewayReference)->toBe(EPS_TXN)
            ->and($result->settlementReference)->toBe('C2549190401');
    });

    it('keeps the payer\'s personal details out of what it records', function () {
        epsFake([
            '*/v1/Auth/GetToken' => epsTokenResponse(),
            '*/v1/EPSEngine/CheckMerchantTransactionStatus*' => epsStatusResponse(),
        ]);

        $raw = json_encode(epsGateway()->verify(EPS_TXN)->raw);

        expect($raw)->toContain('Success')->not->toContain('Nusrat')->not->toContain('01712345678');
    });

    it('status() asks the same single question', function () {
        epsFake([
            '*/v1/Auth/GetToken' => epsTokenResponse(),
            '*/v1/EPSEngine/CheckMerchantTransactionStatus*' => epsStatusResponse(),
        ]);

        expect(epsGateway()->status(EPS_TXN)->isPaid())->toBeTrue();
    });

    it('throws rather than guessing when a success carries no usable amount', function () {
        foreach ([null, '', 'abc', '60.001', '-5'] as $bad) {
            epsFake([
                '*/v1/Auth/GetToken' => epsTokenResponse(),
                '*/v1/EPSEngine/CheckMerchantTransactionStatus*' => epsStatusResponse(['TotalAmount' => $bad]),
            ]);

            expect(fn () => app(EpsGateway::class)->verify(EPS_TXN))->toThrow(GatewayUnavailable::class, 'could not be understood');
        }
    });

    it('refuses an answer about a different transaction', function () {
        epsFake([
            '*/v1/Auth/GetToken' => epsTokenResponse(),
            '*/v1/EPSEngine/CheckMerchantTransactionStatus*' => epsStatusResponse(['MerchantTransactionId' => '9999999999']),
        ]);

        expect(fn () => epsGateway()->verify(EPS_TXN))->toThrow(GatewayUnavailable::class, 'could not be understood');
    });

    it('throws when no status can be found at all', function () {
        epsFake([
            '*/v1/Auth/GetToken' => epsTokenResponse(),
            '*/v1/EPSEngine/CheckMerchantTransactionStatus*' => Http::response(['somethingElse' => true]),
        ]);

        expect(fn () => epsGateway()->verify(EPS_TXN))->toThrow(GatewayUnavailable::class, 'could not be understood');
    });

    it('maps pending, cancelled and failed', function () {
        $cases = ['Pending' => GatewayOutcome::Pending, 'Cancelled' => GatewayOutcome::Cancelled, 'Failed' => GatewayOutcome::Failed];

        foreach ($cases as $status => $outcome) {
            epsFake([
                '*/v1/Auth/GetToken' => epsTokenResponse(),
                '*/v1/EPSEngine/CheckMerchantTransactionStatus*' => epsStatusResponse(['Status' => $status, 'TotalAmount' => '0']),
            ]);

            expect(app(EpsGateway::class)->verify(EPS_TXN)->outcome)->toBe($outcome);
        }
    });

    it('throws when EPS is unreachable or errors', function () {
        epsFake([
            '*/v1/Auth/GetToken' => epsTokenResponse(),
            '*/v1/EPSEngine/CheckMerchantTransactionStatus*' => Http::response('', 500),
        ]);

        expect(fn () => epsGateway()->verify(EPS_TXN))->toThrow(GatewayUnavailable::class);

        epsFake(['*' => Http::failedConnection()]);

        expect(fn () => app(EpsGateway::class)->verify(EPS_TXN))->toThrow(GatewayUnavailable::class, 'connection failed');
    });
});

describe('the browser callback is never trusted', function () {
    it('reports an apparent success as pending, not paid', function () {
        $result = epsGateway()->handleCallback(Request::create('/payment/success', 'GET', [
            'MerchantTransactionId' => EPS_TXN,
            'Status' => 'Success',
        ]));

        expect($result->outcome)->toBe(GatewayOutcome::Pending)
            ->and($result->isPaid())->toBeFalse()
            ->and($result->gatewayReference)->toBe(EPS_TXN);
    });

    it('reads a cancellation and a failure', function () {
        expect(epsGateway()->handleCallback(Request::create('/c', 'GET', ['MerchantTransactionId' => 'x', 'Status' => 'CANCEL']))->outcome)
            ->toBe(GatewayOutcome::Cancelled);

        $failed = epsGateway()->handleCallback(Request::create('/f', 'GET', ['merchantTransactionId' => 'x', 'status' => 'FAILED']));

        expect($failed->outcome)->toBe(GatewayOutcome::Failed)->and($failed->errorCode)->toBe('FAILED');
    });

    it('refuses an encrypted IPN body and fails closed on every signature', function () {
        $result = epsGateway()->handleCallback(Request::create('/webhook/eps', 'POST', ['Data' => 'aXY6Y2lwaGVy']));

        expect($result->errorCode)->toBe('ipn_unsupported')
            ->and(epsGateway()->verifyWebhookSignature(Request::create('/webhook/eps', 'POST', ['Data' => 'x'])))->toBeFalse();
    });
});

describe('what this driver declares', function () {
    it('declares only what the guide documents', function () {
        expect(epsGateway()->capabilities())->toBe([
            GatewayCapability::Initiate,
            GatewayCapability::Verify,
            GatewayCapability::StatusQuery,
        ]);
    });

    it('requires a base url and no device type id', function () {
        expect(epsGateway()->requiredConfiguration())->toBe(['base_url', 'merchant_id', 'store_id', 'username', 'password', 'hash_key']);
    });
});

describe('shipped state (P2-38, §26.4)', function () {
    it('ships disabled', function () {
        expect(app(PaymentGatewayManager::class)->isEnabled('eps'))->toBeFalse();
    });

    it('can be switched on once fully credentialled', function () {
        $this->seed(RolesAndPermissionsSeeder::class);
        $manager = testPlatformStaff(PlatformRole::PaymentManager);

        app(ConfigureGateway::class)->setEnabled($manager, 'eps', true);

        expect(app(PaymentGatewayManager::class)->isEnabled('eps'))->toBeTrue();
    });

    it('holds the credentials EPS issues, encrypted, and never shows them back', function () {
        $this->seed(RolesAndPermissionsSeeder::class);
        $manager = testPlatformStaff(PlatformRole::PaymentManager);

        $this->actingAs($manager)
            ->put(route('admin.gateways.update'), [
                'gateway' => 'eps',
                'mode' => 'live',
                'credentials' => ['hash_key' => 'a-real-hash-key'],
            ])
            ->assertRedirect();

        expect(Setting::query()->where('key', 'payment.eps.live.hash_key')->value('value'))
            ->not->toBe('a-real-hash-key')
            ->and(app(SettingsRepository::class)->get('payment.eps.live.hash_key'))->toBe('a-real-hash-key');

        $page = $this->actingAs($manager)->get(route('admin.gateways.index'));

        expect($page->getContent())->not->toContain('a-real-hash-key')->not->toContain('test-password');
    });

    it('lets an administrator test the connection, and shows no token', function () {
        $this->seed(RolesAndPermissionsSeeder::class);
        $manager = testPlatformStaff(PlatformRole::PaymentManager);

        epsFake(['*/v1/Auth/GetToken' => epsTokenResponse()]);

        $response = $this->actingAs($manager)->post(route('admin.gateways.test-connection'), ['gateway' => 'eps']);

        $response->assertRedirect()->assertSessionHasNoErrors();

        expect(epsSent('/v1/Auth/GetToken'))->toHaveCount(1)
            ->and(json_encode(session()->all()))->not->toContain('eyJ');
    });

    it('reports EPS\'s refusal on the test, safely', function () {
        $this->seed(RolesAndPermissionsSeeder::class);
        $manager = testPlatformStaff(PlatformRole::PaymentManager);

        epsFake(['*/v1/Auth/GetToken' => Http::response(['errorMessage' => 'Invalid user'], 401)]);

        $this->actingAs($manager)
            ->post(route('admin.gateways.test-connection'), ['gateway' => 'eps'])
            ->assertSessionHasErrors(['connection' => 'The eps gateway could not be reached: HTTP 401: Invalid user']);
    });
});
