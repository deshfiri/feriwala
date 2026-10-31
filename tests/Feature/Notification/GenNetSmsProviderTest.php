<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Notification\Actions\ConfigureSms;
use App\Domain\Notification\Actions\ConfigureSmsCredentials;
use App\Domain\Notification\Enums\SmsEvent;
use App\Domain\Notification\Enums\SmsStatus;
use App\Domain\Notification\Jobs\DeliverSmsMessage;
use App\Domain\Notification\Models\SmsMessageRecord;
use App\Domain\Settings\Models\Setting;
use App\Integrations\Sms\Data\SmsMessage;
use App\Integrations\Sms\Exceptions\SmsProviderUnavailable;
use App\Integrations\Sms\Providers\GenNet\GenNetSmsProvider;
use App\Integrations\Sms\Providers\LogSmsProvider;
use App\Integrations\Sms\SmsProviderManager;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * GenNet Push SMS (API v3.0.0) as an additional provider.
 *
 * Every call is a faked HTTP response — nothing here reaches GenNet. The
 * address is a placeholder: the real one is the account's, entered in settings.
 */
const GENNET_TEST_BASE = 'https://api.gennet.example.test';
const GENNET_TEST_TOKEN = 'gennet-secret-token-0001';

function genNetTestConfigure(array $overrides = []): void
{
    app(ConfigureSmsCredentials::class)->handle(
        testPlatformStaff(PlatformRole::SmsManager),
        'gennet',
        [
            'base_url' => GENNET_TEST_BASE,
            'api_token' => GENNET_TEST_TOKEN,
            'sid' => 'BanijTest',
            ...$overrides,
        ],
    );
}

function genNetTestDriver(): GenNetSmsProvider
{
    return app(GenNetSmsProvider::class);
}

/**
 * @param  array<string, mixed>  $row
 * @return array<string, mixed>
 */
function genNetTestRow(string $msisdn, array $row = []): array
{
    return [
        'sms_status' => 'SUCCESS',
        'status_message' => 'Success',
        'msisdn' => $msisdn,
        'sms_type' => 'EN',
        'sms_body' => 'body',
        'csms_id' => 'REF',
        'reference_id' => 'ref-'.$msisdn,
        ...$row,
    ];
}

/**
 * @param  array<int, array<string, mixed>>  $rows
 * @return array<string, mixed>
 */
function genNetTestOk(array $rows): array
{
    return ['status' => 'SUCCESS', 'status_code' => 200, 'error_message' => '', 'smsinfo' => $rows];
}

/**
 * @return array<string, mixed>
 */
function genNetTestFailed(int $code, string $message = 'failed'): array
{
    return ['status' => 'FAILED', 'status_code' => $code, 'error_message' => $message];
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->manager = testPlatformStaff(PlatformRole::SmsManager);
});

describe('being a provider', function () {
    it('is listed beside the existing providers and does not become the default', function () {
        $entry = collect(app(SmsProviderManager::class)->catalogue())->firstWhere('name', 'gennet');

        expect($entry['is_implemented'])->toBeTrue()
            ->and($entry['is_active'])->toBeFalse()
            ->and($entry['required_configuration'])->toBe(['base_url', 'api_token', 'sid'])
            ->and($entry['missing_configuration'])->toBe(['base_url', 'api_token', 'sid'])
            ->and(app(SmsProviderManager::class)->active())->toBe('log')
            ->and(app(SmsProviderManager::class)->driver('log'))->toBeInstanceOf(LogSmsProvider::class);
    });

    it('sends nothing while it is chosen but has no credentials', function () {
        app(ConfigureSms::class)->handle($this->manager, true, 'gennet');

        Http::fake();

        $record = genNetTestQueuedRecord();

        DeliverSmsMessage::dispatchSync($record->id);

        expect(app(SmsProviderManager::class)->canSend())->toBeFalse()
            ->and($record->refresh()->status)->toBe(SmsStatus::Suppressed)
            ->and($record->error)->toBe('No SMS provider is available.');

        Http::assertNothingSent();
    });

    it('can be chosen once configured, and the log provider is still there to go back to', function () {
        genNetTestConfigure();

        app(ConfigureSms::class)->handle($this->manager, true, 'gennet');

        expect(app(SmsProviderManager::class)->active())->toBe('gennet');

        app(ConfigureSms::class)->handle($this->manager, true, 'log');

        expect(app(SmsProviderManager::class)->active())->toBe('log');
    });

    it('holds its credentials encrypted and never records them', function () {
        genNetTestConfigure();

        $stored = Setting::query()->where('key', 'sms.gennet.api_token')->value('value');

        expect($stored)->not->toContain(GENNET_TEST_TOKEN);

        $audit = AuditLog::query()->where('action', 'sms.provider_configured')->latest('id')->firstOrFail();

        expect(json_encode($audit->after))->not->toContain(GENNET_TEST_TOKEN);
    });

    it('treats an address that is not https as missing', function () {
        genNetTestConfigure(['base_url' => 'http://api.gennet.example.test']);

        expect(genNetTestDriver()->missingConfiguration())->toBe(['base_url'])
            ->and(genNetTestDriver()->isConfigured())->toBeFalse();
    });

    it('refuses to send, and makes no request, without credentials', function () {
        Http::fake();

        expect(fn () => genNetTestDriver()->send(new SmsMessage('01712345678', 'Hello')))
            ->toThrow(SmsProviderUnavailable::class);

        Http::assertNothingSent();
    });
});

describe('sending one message', function () {
    beforeEach(fn () => genNetTestConfigure());

    it('posts what the documentation asks for and keeps GenNet\'s reference', function () {
        Http::fake([GENNET_TEST_BASE.'/*' => Http::response(genNetTestOk([genNetTestRow('8801712345678', ['reference_id' => '5da2f0b5ba3a2248110'])]))]);

        $result = genNetTestDriver()->send(new SmsMessage('+8801712345678', 'Your order is on its way.'));

        expect($result->accepted)->toBeTrue()
            ->and($result->providerReference)->toBe('5da2f0b5ba3a2248110');

        Http::assertSent(function (Request $request) {
            $data = $request->data();

            return $request->method() === 'POST'
                && $request->url() === GENNET_TEST_BASE.'/api/v3/send-sms'
                && $data['api_token'] === GENNET_TEST_TOKEN
                && $data['sid'] === 'BanijTest'
                && $data['msisdn'] === '8801712345678'
                && $data['sms'] === 'Your order is on its way.'
                && preg_match('/^[A-Z0-9]{20}$/', $data['csms_id']) === 1;
        });
    });

    it('normalises a national number to GenNet\'s form', function () {
        Http::fake([GENNET_TEST_BASE.'/*' => Http::response(genNetTestOk([genNetTestRow('8801712345678')]))]);

        genNetTestDriver()->send(new SmsMessage('01712-345678', 'Hello'));

        Http::assertSent(fn (Request $request) => $request->data()['msisdn'] === '8801712345678');
    });

    it('sends Bangla exactly as written', function () {
        $bangla = 'আপনার অর্ডারটি পাঠানো হয়েছে।';

        Http::fake([GENNET_TEST_BASE.'/*' => Http::response(genNetTestOk([genNetTestRow('8801712345678', ['sms_type' => 'BN'])]))]);

        expect(genNetTestDriver()->send(new SmsMessage('01712345678', $bangla))->accepted)->toBeTrue();

        Http::assertSent(fn (Request $request) => $request->data()['sms'] === $bangla);
    });

    it('uses a new reference for every message', function () {
        Http::fake([GENNET_TEST_BASE.'/*' => Http::response(genNetTestOk([genNetTestRow('8801712345678')]))]);

        genNetTestDriver()->send(new SmsMessage('01712345678', 'One'));
        genNetTestDriver()->send(new SmsMessage('01712345678', 'Two'));

        $references = Http::recorded()->map(fn (array $pair) => $pair[0]->data()['csms_id'])->all();

        expect($references)->toHaveCount(2)->and($references[0])->not->toBe($references[1]);
    });

    it('refuses a number GenNet would refuse without making a call', function () {
        Http::fake();

        $result = genNetTestDriver()->send(new SmsMessage('12345', 'Hello'));

        expect($result->accepted)->toBeFalse()
            ->and($result->retryable)->toBeFalse()
            ->and($result->errorCode)->toBe('4025');

        Http::assertNothingSent();
    });

    it('refuses a message that is too long without making a call', function () {
        Http::fake();

        $result = genNetTestDriver()->send(new SmsMessage('01712345678', str_repeat('a', 1001)));

        expect($result->accepted)->toBeFalse()->and($result->errorCode)->toBe('4027');

        Http::assertNothingSent();
    });

    it('never calls a rejected message sent', function (int $code) {
        Http::fake([GENNET_TEST_BASE.'/*' => Http::response(genNetTestFailed($code, 'provider words'))]);

        $result = genNetTestDriver()->send(new SmsMessage('01712345678', 'Hello'));

        expect($result->accepted)->toBeFalse()
            ->and($result->retryable)->toBeFalse()
            ->and($result->errorCode)->toBe((string) $code)
            ->and($result->error)->not->toBeEmpty();
    })->with([4001, 4002, 4003, 4004, 4005, 4008, 4020, 4022, 4023, 4025, 4026, 4027, 4028, 4030, 5000]);

    it('says what each refusal means', function () {
        Http::fake([GENNET_TEST_BASE.'/*' => Http::response(genNetTestFailed(4001))]);

        expect(genNetTestDriver()->send(new SmsMessage('01712345678', 'Hello'))->error)
            ->toBe('GenNet rejected the API token.');
    });

    it('retries only the refusals GenNet makes before sending', function (int $code) {
        Http::fake([GENNET_TEST_BASE.'/*' => Http::response(genNetTestFailed($code))]);

        expect(genNetTestDriver()->send(new SmsMessage('01712345678', 'Hello'))->retryable)->toBeTrue();
    })->with([4029, 4031]);

    it('treats a message GenNet reports invalid as refused, even inside a successful request', function () {
        Http::fake([GENNET_TEST_BASE.'/*' => Http::response(genNetTestOk([
            genNetTestRow('8801712345678', ['sms_status' => 'INVALID', 'status_message' => 'Invalid MSISDN']),
        ]))]);

        $result = genNetTestDriver()->send(new SmsMessage('01712345678', 'Hello'));

        expect($result->accepted)->toBeFalse()
            ->and($result->error)->toBe('Invalid MSISDN')
            ->and($result->retryable)->toBeFalse();
    });

    it('does not call an empty success a delivery', function () {
        Http::fake([GENNET_TEST_BASE.'/*' => Http::response(genNetTestOk([]))]);

        expect(genNetTestDriver()->send(new SmsMessage('01712345678', 'Hello'))->accepted)->toBeFalse();
    });

    it('does not retry when the reply is lost, because the message may already have left', function () {
        Http::fake(fn () => throw new ConnectionException('timed out'));

        $result = genNetTestDriver()->send(new SmsMessage('01712345678', 'Hello'));

        expect($result->accepted)->toBeFalse()
            ->and($result->retryable)->toBeFalse()
            ->and($result->error)->toContain('not known')
            ->and(Http::recorded())->toHaveCount(0);
    });

    it('does not retry when the reply is not JSON', function () {
        Http::fake([GENNET_TEST_BASE.'/*' => Http::response('<html>Bad gateway</html>', 502)]);

        $result = genNetTestDriver()->send(new SmsMessage('01712345678', 'Hello'));

        expect($result->accepted)->toBeFalse()->and($result->retryable)->toBeFalse();
        Http::assertSentCount(1);
    });

    it('does not follow a redirect with the token in it', function () {
        Http::fake([
            GENNET_TEST_BASE.'/*' => Http::response('', 302, ['Location' => 'https://elsewhere.example.test/']),
            'https://elsewhere.example.test/*' => Http::response(genNetTestOk([genNetTestRow('8801712345678')])),
        ]);

        expect(genNetTestDriver()->send(new SmsMessage('01712345678', 'Hello'))->accepted)->toBeFalse();

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'elsewhere'));
    });

    it('keeps the token and the message out of the log', function () {
        Log::shouldReceive('channel')->with('sms')->andReturnSelf();
        Log::shouldReceive('info')->once()->withArgs(function (string $message, array $context) {
            $logged = json_encode($context);

            return ! str_contains($logged, GENNET_TEST_TOKEN)
                && ! str_contains($logged, 'secret words')
                && ! str_contains($logged, '8801712345678')
                && $context['path'] === '/api/v3/send-sms';
        });

        Http::fake([GENNET_TEST_BASE.'/*' => Http::response(genNetTestOk([genNetTestRow('8801712345678')]))]);

        genNetTestDriver()->send(new SmsMessage('01712345678', 'secret words'));
    });
});

describe('sending to many', function () {
    beforeEach(fn () => genNetTestConfigure());

    it('sends a bulk message in requests of at most a hundred and matches each number back', function () {
        Http::fake(fn (Request $request) => Http::response(genNetTestOk(array_map(
            fn (string $msisdn) => genNetTestRow($msisdn),
            $request->data()['msisdn'],
        ))));

        $numbers = array_map(fn (int $i) => sprintf('0171%07d', $i), range(1, 150));
        $numbers[] = 'not-a-number';

        $results = genNetTestDriver()->sendBulk($numbers, 'Sale starts today');

        expect($results)->toHaveCount(151)
            ->and($results[0]->accepted)->toBeTrue()
            ->and($results[0]->providerReference)->toBe('ref-8801710000001')
            ->and($results[149]->accepted)->toBeTrue()
            ->and($results[150]->errorCode)->toBe('4025');

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request->url() === GENNET_TEST_BASE.'/api/v3/send-sms/bulk'
            && count($request->data()['msisdn']) <= 100
            && $request->data()['sms'] === 'Sale starts today'
            && strlen($request->data()['batch_csms_id']) === 20);
    });

    it('reports a refused bulk request against every number in it', function () {
        Http::fake([GENNET_TEST_BASE.'/*' => Http::response(genNetTestFailed(4008))]);

        $results = genNetTestDriver()->sendBulk(['01712345678', '01812345678'], 'Hello');

        expect($results[0]->errorCode)->toBe('4008')->and($results[1]->errorCode)->toBe('4008');
    });

    it('does not call a number GenNet left out of its answer sent', function () {
        Http::fake([GENNET_TEST_BASE.'/*' => Http::response(genNetTestOk([genNetTestRow('8801712345678')]))]);

        $results = genNetTestDriver()->sendBulk(['01712345678', '01812345678'], 'Hello');

        expect($results[0]->accepted)->toBeTrue()->and($results[1]->accepted)->toBeFalse();
    });

    it('sends a different message to each number', function () {
        Http::fake(fn (Request $request) => Http::response(genNetTestOk(array_map(
            fn (array $row) => genNetTestRow($row['msisdn'], ['csms_id' => $row['csms_id']]),
            $request->data()['sms'],
        ))));

        $results = genNetTestDriver()->sendDynamic([
            ['to' => '01712345678', 'body' => 'আপনার কোড ১২৩৪'],
            ['to' => '01812345678', 'body' => 'Your code is 5678'],
        ]);

        expect($results[0]->accepted)->toBeTrue()->and($results[1]->accepted)->toBeTrue();

        Http::assertSent(function (Request $request) {
            $sms = $request->data()['sms'];

            return $request->url() === GENNET_TEST_BASE.'/api/v3/send-sms/dynamic'
                && $sms[0]['text'] === 'আপনার কোড ১২৩৪'
                && $sms[1]['msisdn'] === '8801812345678'
                && $sms[0]['csms_id'] !== $sms[1]['csms_id'];
        });
    });
});

describe('balance, delivery and incoming messages', function () {
    beforeEach(fn () => genNetTestConfigure());

    it('reads the balance of the configured sender ID', function () {
        Http::fake([GENNET_TEST_BASE.'/*' => Http::response([
            'status' => 'SUCCESS',
            'status_code' => 200,
            'error_message' => 'Success',
            'smsinfo' => ['balance_info' => [
                ['sender_id' => 'Other', 'sms_balance' => 1],
                ['sender_id' => 'BanijTest', 'sms_balance' => 9404.52],
            ]],
        ])]);

        expect(genNetTestDriver()->balance())->toBe('9404.52');

        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && $request->url() === GENNET_TEST_BASE.'/api/v3/balance'
            && $request->data()['api_token'] === GENNET_TEST_TOKEN);
    });

    it('says why a balance could not be read, and reports none through the contract', function () {
        Http::fake([GENNET_TEST_BASE.'/*' => Http::response(genNetTestFailed(4001))]);

        expect(genNetTestDriver()->balance())->toBeNull()
            ->and(fn () => genNetTestDriver()->fetchBalance())
            ->toThrow(SmsProviderUnavailable::class, 'GenNet rejected the API token.');
    });

    it('reads a message\'s delivery status by its reference', function () {
        Http::fake([GENNET_TEST_BASE.'/*' => Http::response(genNetTestOk([
            genNetTestRow('8801712345678', ['sms_status' => 'Delivered']),
        ]))]);

        expect(genNetTestDriver()->deliveryStatus('5da2f0b5ba3a2248110'))
            ->toBe(['status' => 'Delivered', 'message' => 'Success']);

        Http::assertSent(fn (Request $request) => $request->url() === GENNET_TEST_BASE.'/api/v3/send-sms/status'
            && $request->data()['reference_id'] === '5da2f0b5ba3a2248110');
    });

    it('says why a delivery status could not be read', function () {
        Http::fake([GENNET_TEST_BASE.'/*' => Http::response(genNetTestFailed(4022))]);

        expect(fn () => genNetTestDriver()->deliveryStatus('x'))->toThrow(SmsProviderUnavailable::class);
    });

    it('lists incoming messages', function () {
        Http::fake([GENNET_TEST_BASE.'/*' => Http::response([
            'error' => false,
            'messages' => [['id' => 10, 'sender' => '8801629334432', 'receiver' => '8809612342030', 'message' => 'Hi']],
        ])]);

        $messages = genNetTestDriver()->incomingMessages();

        expect($messages)->toHaveCount(1)->and($messages[0]['message'])->toBe('Hi');
    });

    it('refuses an incoming list GenNet flags as an error', function () {
        Http::fake([GENNET_TEST_BASE.'/*' => Http::response(['error' => true, 'messages' => []])]);

        expect(fn () => genNetTestDriver()->incomingMessages())->toThrow(SmsProviderUnavailable::class);
    });
});

describe('through the existing delivery queue', function () {
    function genNetTestQueuedRecord(string $recipient = '01712345678'): SmsMessageRecord
    {
        return SmsMessageRecord::create([
            'event' => SmsEvent::PaymentReceived->value,
            'recipient' => $recipient,
            'locale' => 'bn',
            'body' => 'আপনার পেমেন্ট পাওয়া গেছে।',
            'segments' => 1,
            'status' => SmsStatus::Queued,
            'queued_at' => now(),
            'dedupe_key' => Str::random(40),
        ]);
    }

    beforeEach(function () {
        genNetTestConfigure();
        app(ConfigureSms::class)->handle($this->manager, true, 'gennet');
    });

    it('records the provider, the reference and the sent state', function () {
        Http::fake([GENNET_TEST_BASE.'/*' => Http::response(genNetTestOk([genNetTestRow('8801712345678', ['reference_id' => 'gn-777'])]))]);

        $record = genNetTestQueuedRecord();

        DeliverSmsMessage::dispatchSync($record->id);

        $record->refresh();

        expect($record->status)->toBe(SmsStatus::Sent)
            ->and($record->provider)->toBe('gennet')
            ->and($record->provider_reference)->toBe('gn-777')
            ->and($record->error)->toBeNull();
    });

    it('records a refusal as failed, with the reason, and sends nothing twice', function () {
        Http::fake([GENNET_TEST_BASE.'/*' => Http::response(genNetTestFailed(4008))]);

        $record = genNetTestQueuedRecord();

        DeliverSmsMessage::dispatchSync($record->id);

        $record->refresh();

        expect($record->status)->toBe(SmsStatus::Failed)
            ->and($record->error)->toBe('The sender ID has insufficient SMS balance.')
            ->and($record->provider_reference)->toBeNull();

        Http::assertSentCount(1);
    });

    it('leaves the log provider working exactly as before once switched back', function () {
        app(ConfigureSms::class)->handle($this->manager, true, 'log');
        Http::fake();

        $record = genNetTestQueuedRecord();

        DeliverSmsMessage::dispatchSync($record->id);

        expect($record->refresh()->status)->toBe(SmsStatus::Sent)
            ->and($record->provider)->toBe('log');

        Http::assertNothingSent();
    });
});

describe('the settings screen', function () {
    it('never sends a stored credential to the browser', function () {
        genNetTestConfigure();

        $response = $this->actingAs($this->manager)->get(route('admin.sms.index'))->assertOk();

        expect(json_encode($response->viewData('page')['props']))
            ->not->toContain(GENNET_TEST_TOKEN)
            ->not->toContain(GENNET_TEST_BASE);
    });

    it('saves GenNet\'s credentials through the existing form', function () {
        $this->actingAs($this->manager)
            ->put(route('admin.sms.credentials.update'), [
                'provider' => 'gennet',
                'credentials' => ['base_url' => GENNET_TEST_BASE, 'api_token' => GENNET_TEST_TOKEN, 'sid' => 'BanijTest'],
            ])
            ->assertRedirect();

        expect(genNetTestDriver()->isConfigured())->toBeTrue();
    });

    it('checks the balance and reports it', function () {
        genNetTestConfigure();
        Http::fake([GENNET_TEST_BASE.'/*' => Http::response([
            'status' => 'SUCCESS', 'status_code' => 200, 'error_message' => 'Success',
            'smsinfo' => ['balance_info' => [['sender_id' => 'BanijTest', 'sms_balance' => 120]]],
        ])]);

        $this->actingAs($this->manager)
            ->post(route('admin.sms.balance'), ['provider' => 'gennet'])
            ->assertRedirect()
            ->assertSessionHas('inertia.flash_data.toast.message', 'Balance: 120');
    });

    it('says why the balance could not be checked', function () {
        genNetTestConfigure();
        Http::fake([GENNET_TEST_BASE.'/*' => Http::response(genNetTestFailed(4001))]);

        $this->actingAs($this->manager)
            ->post(route('admin.sms.balance'), ['provider' => 'gennet'])
            ->assertSessionHas('inertia.flash_data.toast.type', 'error');
    });

    it('refuses to check a provider with no credentials, without calling out', function () {
        Http::fake();

        $this->actingAs($this->manager)
            ->post(route('admin.sms.balance'), ['provider' => 'gennet'])
            ->assertSessionHas('inertia.flash_data.toast.type', 'error');

        Http::assertNothingSent();
    });

    it('sends a test message and audits it with the number masked', function () {
        genNetTestConfigure();
        Http::fake([GENNET_TEST_BASE.'/*' => Http::response(genNetTestOk([genNetTestRow('8801712345678')]))]);

        $this->actingAs($this->manager)
            ->post(route('admin.sms.test'), ['provider' => 'gennet', 'recipient' => '01712345678'])
            ->assertSessionHas('inertia.flash_data.toast.type', 'success');

        $audit = AuditLog::query()->where('action', 'sms.test_sent')->firstOrFail();

        expect($audit->after['accepted'])->toBeTrue()
            ->and(json_encode($audit->after))->not->toContain('01712345678')
            ->and(json_encode($audit->after))->not->toContain(GENNET_TEST_TOKEN);
    });

    it('reports a refused test message with the reason', function () {
        genNetTestConfigure();
        Http::fake([GENNET_TEST_BASE.'/*' => Http::response(genNetTestFailed(4002))]);

        $this->actingAs($this->manager)
            ->post(route('admin.sms.test'), ['provider' => 'gennet', 'recipient' => '01712345678'])
            ->assertSessionHas('inertia.flash_data.toast.type', 'error');
    });

    it('is closed to somebody without the SMS permission', function () {
        genNetTestConfigure();
        Http::fake();

        $paymentManager = testPlatformStaff(PlatformRole::PaymentManager);

        $this->actingAs($paymentManager)
            ->post(route('admin.sms.balance'), ['provider' => 'gennet'])
            ->assertForbidden();

        $this->actingAs($paymentManager)
            ->post(route('admin.sms.test'), ['provider' => 'gennet', 'recipient' => '01712345678'])
            ->assertForbidden();

        Http::assertNothingSent();
    });

    it('will not test a provider with no driver', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.sms.test'), ['provider' => 'twilio', 'recipient' => '01712345678'])
            ->assertSessionHasErrors('provider');
    });
});
