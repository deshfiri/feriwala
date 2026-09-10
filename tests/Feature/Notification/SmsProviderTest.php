<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Notification\Actions\ConfigureSms;
use App\Domain\Settings\SettingsRepository;
use App\Integrations\Sms\Contracts\SmsProvider;
use App\Integrations\Sms\Data\SmsMessage;
use App\Integrations\Sms\Providers\LogSmsProvider;
use App\Integrations\Sms\SmsProviderManager;
use App\Support\Localization\Locale;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The SMS provider layer (P1-55, §30.1).
 *
 * Adding a provider is one class plus a config entry, and nothing that sends a
 * message knows which providers exist. §30 wants the switches in settings rather
 * than config, so turning off a misbehaving provider is not a deploy.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->manager = testPlatformStaff(PlatformRole::SmsManager);
});

describe('resolving a provider', function () {
    it('falls back to the configured default', function () {
        expect(app(SmsProviderManager::class)->active())->toBe('log')
            ->and(app(SmsProvider::class))->toBeInstanceOf(LogSmsProvider::class);
    });

    it('lets a setting outrank the deployed default', function () {
        // An administrator's choice beats what was shipped, and takes effect
        // without a restart.
        config()->set('sms.providers.twilio.driver', LogSmsProvider::class);

        app(ConfigureSms::class)->handle($this->manager, true, 'twilio');

        expect(app(SmsProviderManager::class)->active())->toBe('twilio');
    });

    it('lists every provider §30.1 names, built or not', function () {
        // "Why is Twilio not an option" is answered by a row saying it is not
        // built, never by an absence.
        $catalogue = app(SmsProviderManager::class)->catalogue();

        expect($catalogue)->toHaveCount(5);

        $twilio = collect($catalogue)->firstWhere('name', 'twilio');

        expect($twilio['is_implemented'])->toBeFalse()
            ->and(app(SmsProviderManager::class)->available())->toBe(['log']);
    });

    it('refuses a provider that has no driver', function () {
        expect(fn () => app(SmsProviderManager::class)->driver('twilio'))
            ->toThrow(RuntimeException::class);
    });

    it('refuses a name that is not a provider at all', function () {
        expect(fn () => app(SmsProviderManager::class)->driver('carrier-pigeon'))
            ->toThrow(InvalidArgumentException::class);
    });
});

describe('the global switch', function () {
    it('is on until somebody turns it off', function () {
        expect(app(SmsProviderManager::class)->isEnabled())->toBeTrue()
            ->and(app(SmsProviderManager::class)->canSend())->toBeTrue();
    });

    it('stops everything when it is off', function () {
        // §30's global control: one switch rather than a hunt through every
        // notification class.
        app(ConfigureSms::class)->handle($this->manager, false);

        expect(app(SmsProviderManager::class)->isEnabled())->toBeFalse()
            ->and(app(SmsProviderManager::class)->canSend())->toBeFalse();
    });

    it('cannot be pointed at a provider that cannot send', function () {
        expect(fn () => app(ConfigureSms::class)->handle($this->manager, true, 'twilio'))
            ->toThrow(InvalidArgumentException::class);
    });

    it('records who changed it', function () {
        /*
         * "Nobody received their payment confirmations last week" is a question
         * about a setting somebody changed. An audit entry turns that from an
         * argument into a fact.
         */
        app(ConfigureSms::class)->handle($this->manager, false);

        $entry = AuditLog::query()->where('action', 'sms.settings_changed')->firstOrFail();

        expect($entry->before['enabled'])->toBeTrue()
            ->and($entry->after['enabled'])->toBeFalse()
            ->and($entry->actor_id)->toBe($this->manager->id);
    });
});

describe('counting what a message costs', function () {
    it('knows Bangla is not 160 characters', function () {
        // Every provider encodes Bangla as UCS-2, which cuts a segment from 160
        // to 70. A "one message" template quietly costing three is a bill
        // nobody predicted (§30.2).
        $english = new SmsMessage('01712345678', str_repeat('a', 160));
        $bangla = new SmsMessage('01712345678', str_repeat('অ', 71), Locale::Bangla);

        expect($english->segments())->toBe(1)
            ->and($english->isUnicode())->toBeFalse()
            ->and($bangla->segments())->toBe(2)
            ->and($bangla->isUnicode())->toBeTrue();
    });

    it('masks the recipient even in the development log', function () {
        // A shared development log must not become a list of phone numbers.
        $result = app(LogSmsProvider::class)->send(
            new SmsMessage('+8801712345678', 'Hello'),
        );

        expect($result->accepted)->toBeTrue();
    });
});

describe('the settings screen', function () {
    it('shows what is on and who is carrying it', function () {
        $this->actingAs($this->manager)
            ->get(route('admin.sms.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/sms')
                ->where('settings.enabled', true)
                ->where('settings.provider', 'log')
                ->where('settings.can_send', true)
                ->has('providers', 5)
                ->where('can.manage', true),
            );
    });

    it('saves the switch', function () {
        $this->actingAs($this->manager)
            ->put(route('admin.sms.update'), ['enabled' => false])
            ->assertRedirect();

        expect(app(SmsProviderManager::class)->isEnabled())->toBeFalse()
            ->and(app(SettingsRepository::class)->get(SmsProviderManager::ENABLED_SETTING))
            ->toBeFalse();
    });

    it('turns an unavailable provider into a form error', function () {
        $this->actingAs($this->manager)
            ->put(route('admin.sms.update'), ['enabled' => true, 'provider' => 'twilio'])
            ->assertSessionHasErrors('provider');
    });

    it('is closed to somebody without the SMS permission', function () {
        $paymentManager = testPlatformStaff(PlatformRole::PaymentManager);

        $this->actingAs($paymentManager)
            ->get(route('admin.sms.index'))
            ->assertForbidden();

        $this->actingAs($paymentManager)
            ->put(route('admin.sms.update'), ['enabled' => false])
            ->assertForbidden();
    });
});
