<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Notification\Actions\ConfigureSms;
use App\Domain\Notification\Enums\SmsStatus;
use App\Domain\Notification\Jobs\DeliverSmsMessage;
use App\Domain\Notification\Models\SmsMessageRecord;
use App\Domain\Notification\SmsEventSwitch;
use App\Integrations\Sms\Contracts\SmsProvider;
use App\Integrations\Sms\Data\SmsMessage;
use App\Integrations\Sms\Data\SmsResult;
use App\Models\User;
use App\Notifications\Account\AccountActivated;
use App\Notifications\Billing\PaymentReceived;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Bus;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Queue-based SMS delivery (P1-56, §30.2).
 *
 * §30.2 requires delivery through queues so it never slows the ERP down, plus
 * delivery status, failed-SMS logs, retry and history. The rule underneath all
 * of it: the retry that makes delivery reliable must not make it repetitive.
 */

/**
 * A provider that answers however the test needs it to.
 */
function queuedSmsProvider(SmsResult $result): SmsProvider
{
    return new class($result) implements SmsProvider
    {
        public int $sent = 0;

        public function __construct(private readonly SmsResult $result) {}

        public function send(SmsMessage $message): SmsResult
        {
            $this->sent++;

            return $this->result;
        }

        public function balance(): ?string
        {
            return null;
        }

        public function name(): string
        {
            return 'test';
        }
    };
}

beforeEach(function () {
    $this->recipient = User::factory()->create([
        'mobile' => '01712345678',
        'mobile_verified_at' => now(),
    ]);
});

describe('composing a message', function () {
    it('writes the record before anything is sent', function () {
        /*
         * A record created only on success cannot answer "what happened to the
         * message I never received", which is the only question anybody asks of
         * an SMS log.
         */
        Bus::fake([DeliverSmsMessage::class]);

        $this->recipient->notify(new PaymentReceived('PAY-1', Money::of(600000, Currency::BDT)));

        $record = SmsMessageRecord::query()->firstOrFail();

        expect($record->status)->toBe(SmsStatus::Queued)
            ->and($record->event)->toBe('payment.received')
            ->and($record->recipient)->toBe('01712345678')
            ->and($record->body)->toContain('PAY-1')
            ->and($record->queued_at)->not->toBeNull();

        Bus::assertDispatched(DeliverSmsMessage::class);
    });

    it('hands delivery to the queue rather than doing it inline', function () {
        // §30.2: delivery must never slow the ERP down.
        Bus::fake([DeliverSmsMessage::class]);

        $this->recipient->notify(new AccountActivated);

        Bus::assertDispatched(DeliverSmsMessage::class, fn (DeliverSmsMessage $job) => $job->queue === 'sms');
    });

    it('counts what a Bangla message will cost', function () {
        Bus::fake([DeliverSmsMessage::class]);

        $this->recipient->forceFill(['locale' => 'bn'])->save();
        $this->recipient->notify(new AccountActivated);

        $record = SmsMessageRecord::query()->firstOrFail();

        expect($record->locale)->toBe('bn')
            ->and($record->segments)->toBeGreaterThanOrEqual(1);
    });

    it('sends nothing to an unverified number', function () {
        /*
         * An unverified mobile is one somebody typed and nobody confirmed.
         * Telling it about an account's payments would be telling a stranger.
         */
        Bus::fake([DeliverSmsMessage::class]);

        $this->recipient->forceFill(['mobile_verified_at' => null])->save();
        $this->recipient->notify(new AccountActivated);

        expect(SmsMessageRecord::query()->count())->toBe(0);

        Bus::assertNotDispatched(DeliverSmsMessage::class);
    });

    it('sends nothing to somebody with no number at all', function () {
        Bus::fake([DeliverSmsMessage::class]);

        $this->recipient->forceFill(['mobile' => null, 'mobile_verified_at' => null])->save();
        $this->recipient->notify(new AccountActivated);

        expect(SmsMessageRecord::query()->count())->toBe(0);
    });

    it('writes one message however many times the event is announced', function () {
        /*
         * The heart of it. A gateway sends the same notification several times
         * and settlement is idempotent — but "idempotent" has to reach the
         * customer's phone too.
         */
        Bus::fake([DeliverSmsMessage::class]);

        $notification = new PaymentReceived('PAY-1', Money::of(600000, Currency::BDT));

        $this->recipient->notify($notification);
        $this->recipient->notify(new PaymentReceived('PAY-1', Money::of(600000, Currency::BDT)));

        expect(SmsMessageRecord::query()->count())->toBe(1);
    });

    it('treats a different payment as a different message', function () {
        Bus::fake([DeliverSmsMessage::class]);

        $this->recipient->notify(new PaymentReceived('PAY-1', Money::of(600000, Currency::BDT)));
        $this->recipient->notify(new PaymentReceived('PAY-2', Money::of(700000, Currency::BDT)));

        expect(SmsMessageRecord::query()->count())->toBe(2);
    });
});

describe('delivering it', function () {
    it('marks the record sent when the provider accepts', function () {
        $provider = queuedSmsProvider(SmsResult::accepted('provider-ref-1', '0.35'));
        app()->instance(SmsProvider::class, $provider);

        $this->recipient->notify(new AccountActivated);

        $record = SmsMessageRecord::query()->firstOrFail();

        expect($record->status)->toBe(SmsStatus::Sent)
            ->and($record->provider_reference)->toBe('provider-ref-1')
            ->and($record->cost)->toBe('0.35')
            ->and($record->attempts)->toBe(1)
            ->and($record->sent_at)->not->toBeNull();
    });

    it('records a refusal without retrying it', function () {
        // An invalid number will be invalid on the third attempt too.
        $provider = queuedSmsProvider(SmsResult::rejected('Invalid number.', 'BAD_NUMBER'));
        app()->instance(SmsProvider::class, $provider);

        $this->recipient->notify(new AccountActivated);

        $record = SmsMessageRecord::query()->firstOrFail();

        expect($record->status)->toBe(SmsStatus::Failed)
            ->and($record->error)->toBe('Invalid number.')
            ->and($record->failed_at)->not->toBeNull()
            ->and($provider->sent)->toBe(1);
    });

    it('does not send twice when the job runs again', function () {
        // The queue retries; the record does not.
        $provider = queuedSmsProvider(SmsResult::accepted('ref'));
        app()->instance(SmsProvider::class, $provider);

        $this->recipient->notify(new AccountActivated);

        $record = SmsMessageRecord::query()->firstOrFail();

        app()->call([new DeliverSmsMessage($record->id), 'handle']);

        expect($provider->sent)->toBe(1)
            ->and($record->refresh()->attempts)->toBe(1);
    });
});

describe('the switches', function () {
    beforeEach(function () {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->manager = testPlatformStaff(PlatformRole::SmsManager);
    });

    it('suppresses rather than fails when SMS is off', function () {
        /*
         * A message nobody wanted sent is not a delivery failure. Recording it
         * as one would fill the failed-SMS log with deliberate silence and hide
         * the messages that actually broke.
         */
        $provider = queuedSmsProvider(SmsResult::accepted('ref'));
        app()->instance(SmsProvider::class, $provider);

        app(ConfigureSms::class)->handle($this->manager, false);

        $this->recipient->notify(new AccountActivated);

        expect(SmsMessageRecord::query()->firstOrFail()->status)
            ->toBe(SmsStatus::Suppressed)
            ->and($provider->sent)->toBe(0);
    });

    it('suppresses one event without touching the others', function () {
        $provider = queuedSmsProvider(SmsResult::accepted('ref'));
        app()->instance(SmsProvider::class, $provider);

        app(SmsEventSwitch::class)->set('account.activated', false);

        $this->recipient->notify(new AccountActivated);
        $this->recipient->notify(new PaymentReceived('PAY-1', Money::of(1000, Currency::BDT)));

        $activation = SmsMessageRecord::query()->where('event', 'account.activated')->firstOrFail();
        $payment = SmsMessageRecord::query()->where('event', 'payment.received')->firstOrFail();

        expect($activation->status)->toBe(SmsStatus::Suppressed)
            ->and($payment->status)->toBe(SmsStatus::Sent);
    });

    it('is read at delivery, not at composition', function () {
        // Switching SMS off in a hurry has to stop what is already queued, not
        // only what has not been written yet.
        Bus::fake([DeliverSmsMessage::class]);

        $this->recipient->notify(new AccountActivated);
        $record = SmsMessageRecord::query()->firstOrFail();

        app(ConfigureSms::class)->handle($this->manager, false);

        app()->call([new DeliverSmsMessage($record->id), 'handle']);

        expect($record->refresh()->status)->toBe(SmsStatus::Suppressed);
    });
});

describe('the history screen', function () {
    beforeEach(function () {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->manager = testPlatformStaff(PlatformRole::SmsManager);
    });

    it('shows delivery status with the number masked', function () {
        // The row holds the number in full so a message can be chased; the
        // screen does not, because a screen full of phone numbers leaks them.
        app()->instance(SmsProvider::class, queuedSmsProvider(SmsResult::accepted('ref')));

        $this->recipient->notify(new AccountActivated);

        $this->actingAs($this->manager)
            ->get(route('admin.sms.index'))
            ->assertOk()
            ->assertDontSee('01712345678', escape: false)
            ->assertInertia(fn (Assert $page) => $page
                ->has('messages', 1)
                ->where('messages.0.event', 'account.activated')
                ->where('messages.0.status', 'sent')
                ->where('messages.0.status_label', 'Sent')
                ->where('messages.0.recipient', '017****5678'),
            );
    });

    it('shows a failure with its reason', function () {
        app()->instance(
            SmsProvider::class,
            queuedSmsProvider(SmsResult::rejected('No balance.', 'NO_BALANCE')),
        );

        $this->recipient->notify(new AccountActivated);

        $this->actingAs($this->manager)
            ->get(route('admin.sms.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('messages.0.status', 'failed')
                ->where('messages.0.error', 'No balance.'),
            );
    });
});
