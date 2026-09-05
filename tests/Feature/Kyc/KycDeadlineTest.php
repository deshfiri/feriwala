<?php

use App\Domain\Account\Actions\ChangeAccountStatus;
use App\Domain\Account\Data\AccountStatusChange;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Kyc\Actions\LiftKycDeadlineRestriction;
use App\Domain\Kyc\Actions\OpenKycDraft;
use App\Domain\Kyc\Actions\StartKycResubmission;
use App\Domain\Kyc\Actions\SweepKycDeadlines;
use App\Domain\Kyc\Enums\KycStatus;
use App\Domain\Kyc\KycDeadlines;
use App\Domain\Kyc\Models\KycDeadlineEvent;
use App\Domain\Kyc\Models\KycSubmission;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Integrations\Sms\Contracts\SmsProvider;
use App\Integrations\Sms\Data\SmsMessage;
use App\Integrations\Sms\Data\SmsResult;
use App\Notifications\Kyc\KycDeadlineApproaching;
use App\Notifications\Kyc\KycDeadlineMissed;
use App\Notifications\Kyc\KycDeadlineRestrictionLifted;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();

    $this->settings = app(SettingsRepository::class);

    // A spy rather than a mock: §7.4 says SMS "may be sent", and what these
    // tests care about is whether it was, not how the provider was called.
    $this->sent = collect();
    $this->app->instance(SmsProvider::class, new class($this->sent) implements SmsProvider
    {
        public function __construct(private $sent) {}

        public function send(SmsMessage $message): SmsResult
        {
            $this->sent->push($message);

            return SmsResult::accepted('test-'.$this->sent->count());
        }

        public function name(): string
        {
            return 'spy';
        }

        public function balance(): ?string
        {
            return null;
        }
    });
});

function configureKycDeadline(?int $days = 30, ?int $warnDays = null, bool $restricts = false): void
{
    $settings = test()->settings;

    $settings->define(KycDeadlines::DAYS, 'kyc', SettingType::Integer, $days);
    $settings->define(KycDeadlines::WARN_DAYS, 'kyc', SettingType::Integer, $warnDays);
    $settings->define(KycDeadlines::RESTRICTS_ACTIVE, 'kyc', SettingType::Boolean, $restricts);
}

function sweepDeadlines(): array
{
    return app(SweepKycDeadlines::class)->handle();
}

/**
 * A round the applicant still owes, whose deadline fell `$daysAgo` days back.
 */
function overdueRound(AccountStatus $status = AccountStatus::KycPending, int $daysAgo = 1): KycSubmission
{
    $account = testBusinessAccount($status);

    return KycSubmission::create([
        'business_account_id' => $account->id,
        'status' => KycStatus::Draft,
        'round' => 1,
        'deadline_at' => now()->subDays($daysAgo),
    ]);
}

describe('the configured window (§7.4)', function () {
    it('is off until an administrator sets one', function () {
        // The safe default for a new installation. Inventing thirty days would
        // start restricting real accounts on a number nobody agreed.
        expect(app(KycDeadlines::class)->isEnabled())->toBeFalse();
    });

    it('stamps a deadline on a round when one is configured', function () {
        configureKycDeadline(days: 30);

        $submission = app(OpenKycDraft::class)->handle(testBusinessAccount(AccountStatus::KycPending));

        expect($submission->deadline_at)->not->toBeNull()
            ->and($submission->deadline_at->isSameDay(now()->addDays(30)))->toBeTrue();
    });

    it('leaves the deadline empty when none is configured', function () {
        $submission = app(OpenKycDraft::class)->handle(testBusinessAccount(AccountStatus::KycPending));

        expect($submission->deadline_at)->toBeNull();
    });

    it('starts the clock when the applicant can first act, not at registration', function () {
        // They cannot be late for something that was not open to them.
        configureKycDeadline(days: 30);

        $account = testBusinessAccount(AccountStatus::KycPending);
        $account->forceFill(['created_at' => now()->subMonths(3)])->save();

        $submission = app(OpenKycDraft::class)->handle($account);

        expect($submission->deadline_at->isFuture())->toBeTrue();
    });
});

describe('the sweep', function () {
    it('does nothing at all while deadlines are off', function () {
        overdueRound();

        expect(sweepDeadlines())->toBe(['warned' => 0, 'enforced' => 0]);
    });

    it('acts on a round whose deadline has passed', function () {
        configureKycDeadline();
        $submission = overdueRound();

        expect(sweepDeadlines()['enforced'])->toBe(1)
            ->and(KycDeadlineEvent::where('kyc_submission_id', $submission->id)
                ->where('event', KycDeadlineEvent::ENFORCED)
                ->exists())->toBeTrue();
    });

    it('leaves a round whose deadline is still ahead', function () {
        configureKycDeadline();

        $account = testBusinessAccount(AccountStatus::KycPending);
        KycSubmission::create([
            'business_account_id' => $account->id,
            'status' => KycStatus::Draft,
            'round' => 1,
            'deadline_at' => now()->addDays(5),
        ]);

        expect(sweepDeadlines()['enforced'])->toBe(0);
    });

    it('acts once, however many times it runs', function () {
        // It runs daily and a round stays overdue until it is completed. Without
        // a marker the applicant gets the same SMS every morning.
        configureKycDeadline();
        overdueRound();

        sweepDeadlines();
        sweepDeadlines();
        sweepDeadlines();

        expect(AuditLog::where('action', 'kyc.deadline_missed')->count())->toBe(1)
            ->and($this->sent)->toHaveCount(1);

        Notification::assertSentTimes(KycDeadlineMissed::class, 1);
    });

    it('ignores a round that is waiting on us, not on the applicant', function () {
        // §7.4 is about the applicant failing to complete. A submission sitting
        // in the review queue is our delay, and must not cost them their account.
        configureKycDeadline();

        $account = testBusinessAccount(AccountStatus::KycSubmitted);
        KycSubmission::create([
            'business_account_id' => $account->id,
            'status' => KycStatus::Submitted,
            'round' => 1,
            'deadline_at' => now()->subDays(10),
        ]);

        expect(sweepDeadlines()['enforced'])->toBe(0);
    });

    it('ignores an approved round', function () {
        configureKycDeadline();

        $account = testBusinessAccount(AccountStatus::KycApproved);
        KycSubmission::create([
            'business_account_id' => $account->id,
            'status' => KycStatus::Approved,
            'round' => 1,
            'deadline_at' => now()->subDays(10),
        ]);

        expect(sweepDeadlines()['enforced'])->toBe(0);
    });
});

describe('consequences (§7.4)', function () {
    it('records the action in the audit log', function () {
        configureKycDeadline();
        overdueRound();

        sweepDeadlines();

        $entry = AuditLog::where('action', 'kyc.deadline_missed')->first();

        expect($entry)->not->toBeNull()
            // A scheduled sweep did this. Naming an actor would be a lie an
            // investigation could act on.
            ->and($entry->actor_id)->toBeNull()
            ->and($entry->actor_type)->toBe('system')
            ->and($entry->module)->toBe('kyc');
    });

    it('notifies the owner on both channels', function () {
        configureKycDeadline();
        $submission = overdueRound();

        sweepDeadlines();

        Notification::assertSentTo(
            $submission->businessAccount->owner,
            KycDeadlineMissed::class,
        );

        expect($this->sent)->toHaveCount(1)
            ->and($this->sent->first()->event)->toBe('kyc_deadline_missed');
    });

    it('leaves an unactivated account blocked rather than pushing it backwards', function () {
        // "Activation may remain blocked" — it already is. The deadline stops
        // the clock running in the applicant's favour; it does not undo steps
        // they legitimately completed.
        configureKycDeadline();
        $submission = overdueRound(AccountStatus::KycPending);

        sweepDeadlines();

        expect($submission->businessAccount->fresh()->status)->toBe(AccountStatus::KycPending);
    });

    it('does not restrict an active account unless told to', function () {
        // §7.4 offers restriction rather than requiring it, and restricting a
        // trading business is not something to do on an unset default.
        configureKycDeadline(restricts: false);
        $submission = overdueRound(AccountStatus::Active);

        sweepDeadlines();

        expect($submission->businessAccount->fresh()->status)->toBe(AccountStatus::Active);
    });

    it('restricts an active account when configured to', function () {
        configureKycDeadline(restricts: true);
        $submission = overdueRound(AccountStatus::Active);

        sweepDeadlines();

        expect($submission->businessAccount->fresh()->status)
            ->toBe(AccountStatus::TemporarilyRestricted);
    });

    it('records the restriction in the account history', function () {
        configureKycDeadline(restricts: true);
        $submission = overdueRound(AccountStatus::Active);

        sweepDeadlines();

        $change = $submission->businessAccount->statusHistory()->first();

        expect($change->to_status)->toBe(AccountStatus::TemporarilyRestricted)
            ->and($change->wasAutomatic())->toBeTrue()
            ->and($change->reason)->toContain('KYC deadline');
    });

    it('tells a restricted owner that it is reversible', function () {
        configureKycDeadline(restricts: true);
        $submission = overdueRound(AccountStatus::Active);

        sweepDeadlines();

        Notification::assertSentTo(
            $submission->businessAccount->owner,
            KycDeadlineMissed::class,
            fn (KycDeadlineMissed $notification) => $notification->accountRestricted === true,
        );
    });
});

describe('the warning before the deadline', function () {
    it('is off unless a warning window is configured', function () {
        configureKycDeadline(days: 30, warnDays: null);

        $account = testBusinessAccount(AccountStatus::KycPending);
        KycSubmission::create([
            'business_account_id' => $account->id,
            'status' => KycStatus::Draft,
            'round' => 1,
            'deadline_at' => now()->addDays(2),
        ]);

        expect(sweepDeadlines()['warned'])->toBe(0);
        Notification::assertNothingSent();
    });

    it('warns once when the deadline comes within the window', function () {
        configureKycDeadline(days: 30, warnDays: 7);

        $account = testBusinessAccount(AccountStatus::KycPending);
        KycSubmission::create([
            'business_account_id' => $account->id,
            'status' => KycStatus::Draft,
            'round' => 1,
            'deadline_at' => now()->addDays(3),
        ]);

        sweepDeadlines();
        sweepDeadlines();

        Notification::assertSentTimes(KycDeadlineApproaching::class, 1);
    });

    it('does not warn about a deadline that has already passed', function () {
        // That round gets the overdue notice instead; two messages about the
        // same deadline in one morning is noise, not service.
        configureKycDeadline(days: 30, warnDays: 7);
        overdueRound();

        expect(sweepDeadlines()['warned'])->toBe(0);
        Notification::assertSentTimes(KycDeadlineApproaching::class, 0);
    });
});

describe('resubmission after a missed deadline', function () {
    it('gives the new round a fresh window rather than a spent one', function () {
        // Otherwise the applicant fixes what was asked, resubmits, and is
        // overdue again before they can act.
        configureKycDeadline(days: 30);

        $account = testBusinessAccount(AccountStatus::KycResubmissionRequired);

        KycSubmission::create([
            'business_account_id' => $account->id,
            'status' => KycStatus::ResubmissionRequired,
            'round' => 1,
            'deadline_at' => now()->subDays(5),
        ]);

        $next = app(StartKycResubmission::class)->handle($account);

        expect($next->deadline_at->isFuture())->toBeTrue();
    });

    it('keeps a deadline that has not yet passed', function () {
        // The window covers completing KYC, not each attempt at it — a reviewer
        // answering on day 25 of 30 leaves five days, not a new thirty.
        configureKycDeadline(days: 30);

        $account = testBusinessAccount(AccountStatus::KycResubmissionRequired);
        $deadline = now()->addDays(5)->startOfSecond();

        KycSubmission::create([
            'business_account_id' => $account->id,
            'status' => KycStatus::ResubmissionRequired,
            'round' => 1,
            'deadline_at' => $deadline,
        ]);

        $next = app(StartKycResubmission::class)->handle($account);

        expect($next->deadline_at->timestamp)->toBe($deadline->timestamp);
    });
});

describe('event-level idempotency (closeout 3)', function () {
    it('refuses a second claim on the same event', function () {
        // The guarantee is a unique index, not a flag read a moment earlier:
        // two workers racing on one overdue round resolve at the database.
        configureKycDeadline();
        $submission = overdueRound();

        $first = KycDeadlineEvent::claim($submission, KycDeadlineEvent::ENFORCED);
        $second = KycDeadlineEvent::claim($submission, KycDeadlineEvent::ENFORCED);

        expect($first)->not->toBeNull()
            ->and($second)->toBeNull();
    });

    it('lets a claim already taken block the notification', function () {
        // Claim it by hand, as a crashed first attempt would have, then let the
        // sweep run: it must find the event handled and send nothing.
        configureKycDeadline();
        $submission = overdueRound();

        KycDeadlineEvent::claim($submission, KycDeadlineEvent::ENFORCED);

        expect(sweepDeadlines()['enforced'])->toBe(0);
        Notification::assertNothingSent();
    });

    it('keeps warning and enforcement as separate events', function () {
        configureKycDeadline();
        $submission = overdueRound();

        KycDeadlineEvent::claim($submission, KycDeadlineEvent::WARNED);

        // Warning it does not spend its enforcement.
        expect(KycDeadlineEvent::claim($submission, KycDeadlineEvent::ENFORCED))->not->toBeNull();
    });

    it('will not let an event be edited or deleted', function () {
        configureKycDeadline();
        $event = KycDeadlineEvent::claim(overdueRound(), KycDeadlineEvent::WARNED);

        expect(fn () => $event->update(['event' => 'something-else']))
            ->toThrow(RuntimeException::class, 'append-only')
            ->and(fn () => $event->delete())
            ->toThrow(RuntimeException::class, 'append-only');
    });
});

describe('dashboard notifications (closeout 1)', function () {
    it('puts the overdue notice on the dashboard, not only in mail', function () {
        // D20: mail can be missed, filtered, or sent somewhere nobody reads.
        expect((new KycDeadlineMissed(false))->via(new stdClass))
            ->toContain('database')
            ->toContain('mail');
    });

    it('puts the warning on the dashboard too', function () {
        expect((new KycDeadlineApproaching(3))->via(new stdClass))
            ->toContain('database');
    });

    it('puts the restoration on the dashboard too', function () {
        expect((new KycDeadlineRestrictionLifted)->via(new stdClass))
            ->toContain('database');
    });

    it('persists a dashboard row the account holder can read back', function () {
        configureKycDeadline();
        $submission = overdueRound();

        sweepDeadlines();

        // Notification::fake() intercepts delivery, so assert the channel was
        // asked for rather than the row — the row is Laravel's own concern.
        Notification::assertSentTo(
            $submission->businessAccount->owner,
            KycDeadlineMissed::class,
            fn ($notification, $channels) => in_array('database', $channels, true),
        );
    });
});

describe('the platform timezone (closeout 2)', function () {
    it('is stated rather than inherited from the host', function () {
        // A server rebuilt in another region must not move every deadline.
        expect(config('app.timezone'))->toBe('Asia/Dhaka');
    });

    it('calculates a deadline in that timezone', function () {
        configureKycDeadline(days: 30);

        $submission = app(OpenKycDraft::class)->handle(testBusinessAccount(AccountStatus::KycPending));

        expect($submission->deadline_at->setTimezone(config('app.timezone'))->isSameDay(
            CarbonImmutable::now(config('app.timezone'))->addDays(30)
        ))->toBeTrue();
    });
});

describe('the recovery path (closeout 4)', function () {
    it('lifts the restriction once KYC is approved', function () {
        // A restriction only a human could lift is a trap, not a policy.
        configureKycDeadline(restricts: true);
        $submission = overdueRound(AccountStatus::Active);
        $account = $submission->businessAccount;

        sweepDeadlines();
        expect($account->fresh()->status)->toBe(AccountStatus::TemporarilyRestricted);

        $submission->forceFill(['status' => KycStatus::Approved])->save();

        expect(app(LiftKycDeadlineRestriction::class)->handle($account->fresh()))->toBeTrue()
            ->and($account->fresh()->status)->toBe(AccountStatus::Active);
    });

    it('records the restoration and tells the owner', function () {
        configureKycDeadline(restricts: true);
        $submission = overdueRound(AccountStatus::Active);
        $account = $submission->businessAccount;

        sweepDeadlines();
        $submission->forceFill(['status' => KycStatus::Approved])->save();
        app(LiftKycDeadlineRestriction::class)->handle($account->fresh());

        expect(AuditLog::where('action', 'kyc.deadline_restriction_lifted')->count())->toBe(1);

        Notification::assertSentTo($account->owner, KycDeadlineRestrictionLifted::class);
    });

    it('lifts it only once, however many times it is asked', function () {
        configureKycDeadline(restricts: true);
        $submission = overdueRound(AccountStatus::Active);
        $account = $submission->businessAccount;

        sweepDeadlines();
        $submission->forceFill(['status' => KycStatus::Approved])->save();

        app(LiftKycDeadlineRestriction::class)->handle($account->fresh());
        app(LiftKycDeadlineRestriction::class)->handle($account->fresh());

        Notification::assertSentTimes(KycDeadlineRestrictionLifted::class, 1);
        expect(AuditLog::where('action', 'kyc.deadline_restriction_lifted')->count())->toBe(1);
    });

    it('will not release an account restricted for some other reason', function () {
        // A business restricted by an administrator must not be freed by
        // approving a document.
        configureKycDeadline(restricts: true);

        $account = testBusinessAccount(AccountStatus::Active);
        app(ChangeAccountStatus::class)->handle($account, AccountStatusChange::automatic(
            AccountStatus::TemporarilyRestricted,
            'Something else entirely.',
        ));

        KycSubmission::create([
            'business_account_id' => $account->id,
            'status' => KycStatus::Approved,
            'round' => 1,
        ]);

        expect(app(LiftKycDeadlineRestriction::class)->handle($account->fresh()))->toBeFalse()
            ->and($account->fresh()->status)->toBe(AccountStatus::TemporarilyRestricted);
    });

    it('will not release one whose KYC is still outstanding', function () {
        configureKycDeadline(restricts: true);
        $submission = overdueRound(AccountStatus::Active);
        $account = $submission->businessAccount;

        sweepDeadlines();

        expect(app(LiftKycDeadlineRestriction::class)->handle($account->fresh()))->toBeFalse()
            ->and($account->fresh()->status)->toBe(AccountStatus::TemporarilyRestricted);
    });
});
