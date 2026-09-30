<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\VerificationCodes;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Notification\Actions\ToggleSmsEvent;
use App\Domain\Notification\Enums\SmsEvent;
use App\Domain\Notification\Enums\SmsStatus;
use App\Domain\Notification\Exceptions\SmsEventSwitchedOff;
use App\Domain\Notification\Models\SmsMessageRecord;
use App\Domain\Notification\SmsEventSwitch;
use App\Domain\Supplier\Actions\SendSupplierMobileVerificationCode;
use App\Domain\Supplier\Models\Supplier;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The per-event SMS switch on the admin SMS screen (§30).
 *
 * §30 requires SMS to be disableable by notification event as well as
 * globally. Every event is listed with its own switch; one-time codes are
 * listed but cannot be switched off, because silencing one locks somebody out
 * of signing up or confirming an order.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->manager = testPlatformStaff(PlatformRole::SmsManager);
});

function smsEventSwitchRecord(string $event, SmsStatus $status): SmsMessageRecord
{
    return SmsMessageRecord::create([
        'event' => $event,
        'recipient' => '01700000000',
        'locale' => 'en',
        'body' => 'Test',
        'segments' => 1,
        'status' => $status,
        'queued_at' => now(),
        'dedupe_key' => Str::random(40),
    ]);
}

it('lists every SMS event with its own switch and how many it has sent', function () {
    smsEventSwitchRecord(SmsEvent::PaymentReceived->value, SmsStatus::Sent);
    smsEventSwitchRecord(SmsEvent::PaymentReceived->value, SmsStatus::Sent);
    smsEventSwitchRecord(SmsEvent::PaymentReceived->value, SmsStatus::Suppressed);

    $this->actingAs($this->manager)
        ->get(route('admin.sms.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('events', count(SmsEvent::cases()))
            ->where('events.0.event', 'account.activated')
            ->where('events.0.title', 'Account activated')
            ->where('events.0.one_time_code', false)
            ->where('events.0.enabled', true)
            ->where('events.1.event', 'payment.received')
            // Only messages that actually went out count as sent.
            ->where('events.1.sent', 2)
            ->where('events.4.event', 'mobile_verification')
            ->where('events.4.one_time_code', true)
            ->where('events.4.enabled', true),
        );
});

it('switches one event off and back on, and records both in the audit log', function () {
    $this->actingAs($this->manager)
        ->put(route('admin.sms.events.toggle'), ['event' => 'payment.received', 'enabled' => false])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(app(SmsEventSwitch::class)->isEnabledFor('payment.received'))->toBeFalse()
        // The others are untouched.
        ->and(app(SmsEventSwitch::class)->isEnabledFor('account.activated'))->toBeTrue();

    $this->actingAs($this->manager)
        ->get(route('admin.sms.index'))
        ->assertInertia(fn (Assert $page) => $page->where('events.1.enabled', false));

    $this->actingAs($this->manager)
        ->put(route('admin.sms.events.toggle'), ['event' => 'payment.received', 'enabled' => true])
        ->assertRedirect();

    expect(app(SmsEventSwitch::class)->isEnabledFor('payment.received'))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'sms.event_disabled')->where('actor_id', $this->manager->id)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'sms.event_enabled')->where('actor_id', $this->manager->id)->exists())->toBeTrue();
});

it('lets an administrator switch a one-time code off and back on', function (SmsEvent $event) {
    $this->actingAs($this->manager)
        ->put(route('admin.sms.events.toggle'), ['event' => $event->value, 'enabled' => false])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(app(SmsEventSwitch::class)->isSwitchedOn($event->value))->toBeFalse()
        ->and(AuditLog::query()->where('action', 'sms.event_disabled')->exists())->toBeTrue();

    $this->actingAs($this->manager)
        ->put(route('admin.sms.events.toggle'), ['event' => $event->value, 'enabled' => true])
        ->assertRedirect();

    expect(app(SmsEventSwitch::class)->isSwitchedOn($event->value))->toBeTrue();
})->with([
    'customer verification' => SmsEvent::MobileVerification,
    'supplier verification' => SmsEvent::SupplierMobileVerification,
    'cash on delivery confirmation' => SmsEvent::CodConfirmation,
]);

it('issues no supplier code while supplier verification codes are switched off', function () {
    app(SmsEventSwitch::class)->set(SmsEvent::SupplierMobileVerification->value, false);
    $supplier = Supplier::factory()->create();

    expect(fn () => app(SendSupplierMobileVerificationCode::class)->handle($supplier))
        ->toThrow(SmsEventSwitchedOff::class)
        ->and(app(VerificationCodes::class)->isPending(SendSupplierMobileVerificationCode::PURPOSE, (string) $supplier->mobile))
        ->toBeFalse();
});

it('refuses an event it does not know', function () {
    $this->actingAs($this->manager)
        ->put(route('admin.sms.events.toggle'), ['event' => 'something.else', 'enabled' => false])
        ->assertSessionHasErrors('event');
});

it('is closed to somebody without permission to manage SMS', function () {
    $paymentManager = testPlatformStaff(PlatformRole::PaymentManager);

    $this->actingAs($paymentManager)
        ->put(route('admin.sms.events.toggle'), ['event' => 'payment.received', 'enabled' => false])
        ->assertForbidden();

    expect(app(SmsEventSwitch::class)->isSwitchedOn('payment.received'))->toBeTrue()
        ->and(fn () => app(ToggleSmsEvent::class)->handle($paymentManager, SmsEvent::PaymentReceived, false))
        ->toThrow(AuthorizationException::class);
});

it('has a switch for every event the code sends an SMS under', function () {
    // A new message added without its catalogue entry would send with no way
    // to turn it off. Every `event:` given to an `SmsMessage` must be listed.
    $sent = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        if (! str_contains($source, 'new SmsMessage(')) {
            continue;
        }

        preg_match_all("/event:\s*'([^']+)'/", $source, $literal);
        preg_match_all('/event:\s*SmsEvent::(\w+)->value/', $source, $cases);

        $sent = [...$sent, ...$literal[1]];

        foreach ($cases[1] as $case) {
            $sent[] = constant(SmsEvent::class.'::'.$case)->value;
        }
    }

    expect($sent)->not->toBeEmpty()
        ->and(array_values(array_diff(array_unique($sent), array_map(fn (SmsEvent $event) => $event->value, SmsEvent::cases()))))
        ->toBe([]);
});
