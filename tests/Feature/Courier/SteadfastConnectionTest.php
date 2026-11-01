<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Courier\Actions\ConfigureSteadfast;
use App\Domain\Courier\Actions\RotateSteadfastWebhookToken;
use App\Domain\Courier\Actions\TestSteadfastConnection;
use App\Domain\Courier\Enums\CourierProviderCode;
use App\Domain\Courier\Models\CourierProvider;
use App\Domain\Courier\Models\Shipment;
use App\Domain\Settings\Models\Setting;
use App\Domain\Settings\SettingsRepository;
use App\Integrations\Courier\Steadfast\SteadfastCredentials;
use App\Integrations\Courier\Steadfast\SteadfastFailureKind;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
 * Steadfast's configuration and Test connection. Every call is a faked HTTP
 * response -- nothing here reaches Steadfast. The contract under test is the one
 * Steadfast's maintained Laravel package demonstrates (GET /get_balance, with
 * Api-Key and Secret-Key headers); see requirements/ for the audit.
 */
const STEADFAST_TEST_BASE = 'https://portal.steadfast.com.bd/api/v1';
const STEADFAST_TEST_KEY = 'sf-api-key-AAAA1111';
const STEADFAST_TEST_SECRET = 'sf-secret-key-BBBB2222';

function steadfastTestConfigure(bool $enabled = true): void
{
    app(ConfigureSteadfast::class)->handle(
        testPlatformStaff(PlatformRole::CourierManager),
        $enabled,
        null,
        ['api_key' => STEADFAST_TEST_KEY, 'secret_key' => STEADFAST_TEST_SECRET],
    );
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->manager = testPlatformStaff(PlatformRole::CourierManager);
});

describe('configuration', function () {
    it('holds the keys encrypted and records only which fields changed', function () {
        steadfastTestConfigure();

        $stored = Setting::query()->where('key', SteadfastCredentials::API_KEY)->value('value');

        expect($stored)->not->toContain(STEADFAST_TEST_KEY)
            ->and(app(SteadfastCredentials::class)->apiKey())->toBe(STEADFAST_TEST_KEY)
            ->and(app(SteadfastCredentials::class)->secretKey())->toBe(STEADFAST_TEST_SECRET);

        $audit = AuditLog::query()->where('action', 'courier.steadfast_configured')->latest('id')->firstOrFail();
        $recorded = json_encode($audit->getAttributes());

        expect($recorded)->not->toContain(STEADFAST_TEST_KEY)
            ->and($recorded)->not->toContain(STEADFAST_TEST_SECRET)
            ->and($recorded)->toContain('api_key');
    });

    it('leaves a saved key alone when the field is left blank', function () {
        steadfastTestConfigure();

        app(ConfigureSteadfast::class)->handle($this->manager, true, null, ['api_key' => '', 'secret_key' => null]);

        expect(app(SteadfastCredentials::class)->apiKey())->toBe(STEADFAST_TEST_KEY);
    });

    it('refuses to switch on while a key is missing', function () {
        expect(fn () => app(ConfigureSteadfast::class)->handle($this->manager, true, null, ['api_key' => STEADFAST_TEST_KEY]))
            ->toThrow(InvalidArgumentException::class);

        // The attempt is rolled back whole: nothing half-saved, still switched off.
        expect(app(SteadfastCredentials::class)->apiKey())->toBeNull()
            ->and(CourierProvider::query()->where('code', CourierProviderCode::Steadfast->value)->value('is_enabled'))->toBeFalse();
    });

    it('never sends a saved key to the browser', function () {
        steadfastTestConfigure();

        $response = $this->actingAs($this->manager)->get(route('admin.courier.steadfast.index'))->assertOk();
        $page = $response->getContent();

        expect($page)->not->toContain(STEADFAST_TEST_KEY)
            ->and($page)->not->toContain(STEADFAST_TEST_SECRET);

        $response->assertInertia(fn ($inertia) => $inertia
            ->where('settings.has_api_key', true)
            ->where('settings.has_secret_key', true)
            ->where('settings.missing', [])
            ->where('callback_url', url('webhooks/courier/steadfast')));
    });

    it('is only for those who manage courier integrations', function () {
        $other = testPlatformStaff(PlatformRole::SmsManager);

        $this->actingAs($other)->get(route('admin.courier.steadfast.index'))->assertForbidden();
        $this->actingAs($other)->put(route('admin.courier.steadfast.update'), ['enabled' => true])->assertForbidden();
        $this->actingAs($other)->post(route('admin.courier.steadfast.test'))->assertForbidden();
        $this->actingAs($other)->post(route('admin.courier.steadfast.webhook-token'))->assertForbidden();
    });
});

describe('the base address', function () {
    it('defaults to the host Steadfast documents', function () {
        expect(app(SteadfastCredentials::class)->baseUrl())->toBe(STEADFAST_TEST_BASE);
    });

    it('in production allows only the official https host', function () {
        app()->detectEnvironment(fn () => 'production');

        foreach ([
            'https://portal.steadfast.com.bd/api/v1',
            'https://PORTAL.steadfast.com.bd/api/v1/',
        ] as $allowed) {
            expect(SteadfastCredentials::isPermittedBaseUrl(rtrim($allowed, '/')))->toBeTrue();
        }

        foreach ([
            'http://portal.steadfast.com.bd/api/v1',
            'https://portal.packzy.com/api/v1',
            'https://evil.example.com/api/v1',
            'https://portal.steadfast.com.bd.evil.com/api/v1',
            'https://user:pass@portal.steadfast.com.bd/api/v1',
            'https://portal.steadfast.com.bd:8443/api/v1',
            'https://portal.steadfast.com.bd/api/v1?x=1',
            'https://127.0.0.1/api/v1',
            'ftp://portal.steadfast.com.bd',
            'not a url',
        ] as $refused) {
            expect(SteadfastCredentials::isPermittedBaseUrl($refused))->toBeFalse($refused);
        }
    });

    it('refuses to save an address that is not allowed', function () {
        app()->detectEnvironment(fn () => 'production');

        expect(fn () => app(ConfigureSteadfast::class)->handle($this->manager, false, 'https://evil.example.com/api/v1', []))
            ->toThrow(InvalidArgumentException::class);

        expect(Setting::query()->where('key', SteadfastCredentials::BASE_URL)->exists())->toBeFalse();
    });
});

describe('the webhook token', function () {
    it('is generated by Banij, stored encrypted, shown once and never audited', function () {
        $token = app(RotateSteadfastWebhookToken::class)->handle($this->manager);

        expect(strlen($token))->toBeGreaterThanOrEqual(40)
            ->and(Setting::query()->where('key', SteadfastCredentials::WEBHOOK_TOKEN)->value('value'))->not->toContain($token)
            ->and(app(SteadfastCredentials::class)->webhookToken())->toBe($token);

        $audit = AuditLog::query()->where('action', 'courier.steadfast_webhook_token_rotated')->firstOrFail();

        expect(json_encode($audit->getAttributes()))->not->toContain($token);

        $second = app(RotateSteadfastWebhookToken::class)->handle($this->manager);

        expect($second)->not->toBe($token)
            ->and(app(SteadfastCredentials::class)->webhookToken())->toBe($second);
    });
});

describe('the webhook token endpoint', function () {
    it('answers once, uncacheable, and keeps the token out of the session and later pages', function () {
        $response = $this->actingAs($this->manager)->postJson(route('admin.courier.steadfast.webhook-token'))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');

        $token = $response->json('token');

        expect($token)->toBe(app(SteadfastCredentials::class)->webhookToken());

        $page = $this->actingAs($this->manager)->get(route('admin.courier.steadfast.index'))->assertOk()->getContent();

        expect($page)->not->toContain($token);
        $response->assertSessionMissing('token');
    });
});

describe('test connection', function () {
    it('asks only for the balance, with the documented headers, and books nothing', function () {
        steadfastTestConfigure();

        Http::fake([STEADFAST_TEST_BASE.'/*' => Http::response('{"status":200,"current_balance":1250.5}')]);

        $before = [Shipment::query()->count(), DB::table('courier_booking_attempts')->count()];

        $result = app(TestSteadfastConnection::class)->handle($this->manager);

        expect($result->ok)->toBeTrue()
            ->and($result->balance->toDecimal())->toBe('1250.50');

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && $request->url() === STEADFAST_TEST_BASE.'/get_balance'
            && $request->header('Api-Key') === [STEADFAST_TEST_KEY]
            && $request->header('Secret-Key') === [STEADFAST_TEST_SECRET]
            && $request->header('Content-Type') === ['application/json']);

        expect([Shipment::query()->count(), DB::table('courier_booking_attempts')->count()])->toBe($before);
    });

    it('remembers whether the connection was ever verified', function () {
        steadfastTestConfigure();

        Http::fake([STEADFAST_TEST_BASE.'/*' => Http::response('{"status":200,"current_balance":0}')]);

        app(TestSteadfastConnection::class)->handle($this->manager);

        expect(app(SettingsRepository::class)->get(SteadfastCredentials::LAST_TEST_OK))->toBeTrue();
    });

    it('does not call out at all while not configured', function () {
        Http::fake();

        $result = app(TestSteadfastConnection::class)->handle($this->manager);

        expect($result->ok)->toBeFalse()
            ->and($result->failure)->toBe(SteadfastFailureKind::NotConfigured);

        Http::assertNothingSent();
    });

    it('tells the failure kinds apart', function (callable $fake, SteadfastFailureKind $kind) {
        steadfastTestConfigure();

        Http::fake($fake);

        expect(app(TestSteadfastConnection::class)->handle($this->manager)->failure)->toBe($kind);
    })->with([
        'a rejected key' => [fn () => Http::response('{"message":"Unauthorized"}', 401), SteadfastFailureKind::Authentication],
        'forbidden' => [fn () => Http::response('', 403), SteadfastFailureKind::Authentication],
        'a timeout' => [fn () => throw new ConnectionException('cURL error 28: Operation timed out'), SteadfastFailureKind::Timeout],
        'an unreachable host' => [fn () => throw new ConnectionException('cURL error 6: Could not resolve host'), SteadfastFailureKind::Unreachable],
        'a validation error' => [fn () => Http::response('{"errors":{}}', 422), SteadfastFailureKind::Validation],
        'a server error' => [fn () => Http::response('<html>Bad gateway</html>', 502), SteadfastFailureKind::Unexpected],
        'a body that is not JSON' => [fn () => Http::response('hello', 200), SteadfastFailureKind::Unexpected],
        'no balance in the answer' => [fn () => Http::response('{"status":200}', 200), SteadfastFailureKind::Unexpected],
        'a status that is not 200' => [fn () => Http::response('{"status":400,"current_balance":1}', 200), SteadfastFailureKind::Unexpected],
        'a balance that is not a number' => [fn () => Http::response('{"status":200,"current_balance":"lots"}', 200), SteadfastFailureKind::Unexpected],
    ]);

    it('does not follow a redirect with the keys attached', function () {
        steadfastTestConfigure();

        Http::fake([STEADFAST_TEST_BASE.'/*' => Http::response('', 302, ['Location' => 'https://evil.example.com/'])]);

        expect(app(TestSteadfastConnection::class)->handle($this->manager)->failure)->toBe(SteadfastFailureKind::Unexpected);

        Http::assertSentCount(1);
    });

    it('writes neither a key nor a response into logs, audit or the screen message', function () {
        steadfastTestConfigure();

        Log::spy();
        Http::fake([STEADFAST_TEST_BASE.'/*' => Http::response('{"status":200,"current_balance":10,"secret":"RESPONSE-MARKER"}')]);

        $this->actingAs($this->manager)->post(route('admin.courier.steadfast.test'))->assertRedirect();

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context = []) {
            $logged = json_encode($context);

            return ! str_contains($logged, STEADFAST_TEST_KEY)
                && ! str_contains($logged, STEADFAST_TEST_SECRET)
                && ! str_contains($logged, 'RESPONSE-MARKER');
        })->atLeast()->once();

        $audit = json_encode(AuditLog::query()->where('action', 'courier.steadfast_connection_tested')->get()->map->getAttributes()->all());

        expect($audit)->not->toContain(STEADFAST_TEST_KEY)
            ->and($audit)->not->toContain('RESPONSE-MARKER');
    });
});
