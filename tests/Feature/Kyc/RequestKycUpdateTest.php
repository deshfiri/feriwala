<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Kyc\Actions\RequestKycUpdate;
use App\Domain\Kyc\Enums\KycStatus;
use App\Domain\Kyc\KycDeadlines;
use App\Domain\Kyc\Models\KycSubmission;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Models\User;
use App\Notifications\Kyc\KycUpdateRequested;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/*
 * Requesting a future KYC update (P1-63, §7.2, §7.4).
 *
 * The distinguishing facts: the account is already trading, the request must
 * not stop it trading, and the deadline can be set for this request rather than
 * only inherited from the standing window.
 */

/** An activated account with an approved KYC round behind it. */
function kycUpdateTestAccount(): BusinessAccount
{
    $account = testBusinessAccount();

    KycSubmission::create([
        'business_account_id' => $account->id,
        'status' => KycStatus::Approved,
        'round' => 1,
        'submitted_at' => now()->subMonths(11),
        'reviewed_at' => now()->subMonths(11),
    ]);

    return $account;
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    $this->officer = testPlatformStaff(PlatformRole::KycManager);
});

describe('opening the round', function () {
    it('opens a new round marked as requested', function () {
        $account = kycUpdateTestAccount();

        $submission = app(RequestKycUpdate::class)->handle(
            $account,
            $this->officer,
            'Trade licence expires next month.',
            'Please upload your renewed trade licence.',
        );

        expect($submission->round)->toBe(2)
            ->and($submission->status)->toBe(KycStatus::Draft)
            ->and($submission->wasRequested())->toBeTrue()
            ->and($submission->requested_by)->toBe($this->officer->id);
    });

    it('keeps the internal reason and the instruction apart', function () {
        // §7.3's separation, for the same reason: one is for colleagues, the
        // other goes in front of a customer.
        $account = kycUpdateTestAccount();

        $submission = app(RequestKycUpdate::class)->handle(
            $account,
            $this->officer,
            'Flagged by the sanctions screen.',
            'Please upload your renewed trade licence.',
        );

        expect($submission->request_reason)->toBe('Flagged by the sanctions screen.')
            ->and($submission->request_instructions)->toBe('Please upload your renewed trade licence.');
    });

    it('leaves the business trading', function () {
        /*
         * Asking a live business for a document and stopping its orders in the
         * same breath would punish it for a request it has not had a chance to
         * answer. §7.4's restriction is what applies if the deadline passes.
         */
        $account = kycUpdateTestAccount();

        app(RequestKycUpdate::class)->handle(
            $account,
            $this->officer,
            'Periodic re-verification.',
            'Please re-upload your identity document.',
        );

        expect($account->refresh()->status)->toBe(AccountStatus::Active)
            ->and($account->canTransact())->toBeTrue();
    });

    it('notifies the account owner on the dashboard and by mail', function () {
        // D20: an account-status message, and not optional — one that can be
        // switched off is one an account can miss and be restricted for.
        $account = kycUpdateTestAccount();

        app(RequestKycUpdate::class)->handle(
            $account,
            $this->officer,
            'Reason.',
            'Please upload a fresh bank statement.',
        );

        Notification::assertSentTo(
            $account->owner,
            KycUpdateRequested::class,
            function (KycUpdateRequested $notification) use ($account) {
                expect($notification->via($account->owner))->toBe(['database', 'mail']);

                return true;
            },
        );
    });

    it('tells the account holder what to do, never why we asked', function () {
        $account = kycUpdateTestAccount();

        $submission = app(RequestKycUpdate::class)->handle(
            $account,
            $this->officer,
            'Flagged by the sanctions screen.',
            'Please upload your renewed trade licence.',
        );

        $payload = (new KycUpdateRequested($submission))->toArray($account->owner);

        expect($payload['instructions'])->toBe('Please upload your renewed trade licence.')
            ->and(json_encode($payload))->not->toContain('sanctions');
    });

    it('records the request in the audit log', function () {
        $account = kycUpdateTestAccount();

        app(RequestKycUpdate::class)->handle(
            $account,
            $this->officer,
            'Trade licence expires next month.',
            'Please upload your renewed trade licence.',
        );

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'kyc.update_requested',
            'reason' => 'Trade licence expires next month.',
        ]);
    });
});

describe('the deadline', function () {
    it('takes an explicit one, which is the point of §7.2 offering it', function () {
        // "Within seven days" is a different instruction from the standing
        // window, and a request that could only use the default could not say so.
        $account = kycUpdateTestAccount();
        $deadline = CarbonImmutable::now()->addDays(7)->startOfDay();

        $submission = app(RequestKycUpdate::class)->handle(
            $account,
            $this->officer,
            'Reason.',
            'Instructions.',
            $deadline,
        );

        expect($submission->deadline_at->toDateString())->toBe($deadline->toDateString());
    });

    it('falls back to the configured window when none is given', function () {
        app(SettingsRepository::class)->define(
            KycDeadlines::DAYS, 'kyc', SettingType::Integer, 30,
        );

        $account = kycUpdateTestAccount();

        $submission = app(RequestKycUpdate::class)->handle(
            $account, $this->officer, 'Reason.', 'Instructions.',
        );

        expect($submission->deadline_at->toDateString())
            ->toBe(now()->addDays(30)->toDateString());
    });

    it('leaves the request open-ended when nothing is configured', function () {
        // Inventing an expiry would start restricting a real business on a
        // number nobody agreed (§7.4).
        $account = kycUpdateTestAccount();

        $submission = app(RequestKycUpdate::class)->handle(
            $account, $this->officer, 'Reason.', 'Instructions.',
        );

        expect($submission->deadline_at)->toBeNull()
            ->and($submission->isOverdue())->toBeFalse();
    });

    it('refuses a deadline in the past', function () {
        $account = kycUpdateTestAccount();

        expect(fn () => app(RequestKycUpdate::class)->handle(
            $account,
            $this->officer,
            'Reason.',
            'Instructions.',
            CarbonImmutable::now()->subDay(),
        ))->toThrow(InvalidArgumentException::class, 'cannot be in the past');
    });

    it('becomes overdue like any other round, so the §7.4 sweep finds it', function () {
        $account = kycUpdateTestAccount();

        $submission = app(RequestKycUpdate::class)->handle(
            $account,
            $this->officer,
            'Reason.',
            'Instructions.',
            CarbonImmutable::now()->addDay(),
        );

        $this->travel(2)->days();

        expect($submission->refresh()->isOverdue())->toBeTrue();
    });
});

describe('what it refuses', function () {
    it('refuses without an internal reason or instructions', function (string $missing) {
        $account = kycUpdateTestAccount();

        expect(fn () => app(RequestKycUpdate::class)->handle(
            $account,
            $this->officer,
            $missing === 'reason' ? '  ' : 'Reason.',
            $missing === 'instructions' ? '  ' : 'Instructions.',
        ))->toThrow(InvalidArgumentException::class);
    })->with(['reason', 'instructions']);

    it('refuses an account that has never submitted KYC', function () {
        expect(fn () => app(RequestKycUpdate::class)->handle(
            testBusinessAccount(),
            $this->officer,
            'Reason.',
            'Instructions.',
        ))->toThrow(InvalidArgumentException::class, 'never submitted KYC');
    });

    it('refuses while a round is already open', function (KycStatus $status) {
        /*
         * Two open rounds would give the account holder no way to know which
         * one the request refers to — and if ours is waiting on a reviewer, the
         * delay is ours rather than theirs.
         */
        $account = kycUpdateTestAccount();

        KycSubmission::create([
            'business_account_id' => $account->id,
            'status' => $status,
            'round' => 2,
        ]);

        expect(fn () => app(RequestKycUpdate::class)->handle(
            $account, $this->officer, 'Reason.', 'Instructions.',
        ))->toThrow(InvalidArgumentException::class, 'already has a KYC round in progress');
    })->with([KycStatus::Draft, KycStatus::Submitted, KycStatus::UnderReview]);

    it('opens only one round when the request is repeated', function () {
        /*
         * Repeated clicks, a retry, two staff at once. The account row is
         * locked for the whole read-decide-write, and the unique index on
         * (account, round) stands behind it — so a duplicate cannot create a
         * second deadline, a second notification or a second audit entry.
         */
        $account = kycUpdateTestAccount();
        $action = app(RequestKycUpdate::class);

        $action->handle($account, $this->officer, 'Reason.', 'Instructions.');

        expect(fn () => $action->handle($account, $this->officer, 'Reason.', 'Instructions.'))
            ->toThrow(InvalidArgumentException::class, 'already has a KYC round in progress');

        expect(KycSubmission::query()->where('business_account_id', $account->id)->count())
            ->toBe(2);

        Notification::assertSentToTimes($account->owner, KycUpdateRequested::class, 1);

        expect(DB::table('audit_logs')->where('action', 'kyc.update_requested')->count())
            ->toBe(1);
    });

    it('writes nothing at all when the request is refused', function () {
        // A refusal must leave no round, no deadline and no audit entry behind.
        $account = kycUpdateTestAccount();
        KycSubmission::create([
            'business_account_id' => $account->id,
            'status' => KycStatus::Draft,
            'round' => 2,
        ]);

        try {
            app(RequestKycUpdate::class)->handle($account, $this->officer, 'Reason.', 'Instructions.');
        } catch (InvalidArgumentException) {
            // Expected.
        }

        expect(KycSubmission::query()->where('business_account_id', $account->id)->count())
            ->toBe(2);

        Notification::assertNothingSent();

        expect(DB::table('audit_logs')->where('action', 'kyc.update_requested')->count())
            ->toBe(0);
    });

    it('allows a fresh request after an earlier one was approved', function () {
        $account = kycUpdateTestAccount();

        $first = app(RequestKycUpdate::class)->handle(
            $account, $this->officer, 'First.', 'Instructions.',
        );

        $first->forceFill(['status' => KycStatus::Approved, 'reviewed_at' => now()])->save();

        $second = app(RequestKycUpdate::class)->handle(
            $account, $this->officer, 'Second.', 'Instructions.',
        );

        expect($second->round)->toBe(3);
    });
});

describe('who may ask', function () {
    it('is a verification permission, not an approval one', function () {
        // Someone monitoring expiring documents should be able to ask for a new
        // copy without also being able to let an account onto the platform.
        $account = kycUpdateTestAccount();

        expect($this->officer->can('requestUpdate', [KycSubmission::class, $account]))
            ->toBeTrue();
    });

    it('refuses somebody with no permission at all', function () {
        $account = kycUpdateTestAccount();

        expect(User::factory()->staff()->create()->can('requestUpdate', [KycSubmission::class, $account]))
            ->toBeFalse();
    });

    it('refuses somebody asking it of their own account', function () {
        // Nobody requires verification of themselves, whatever else they hold.
        $account = kycUpdateTestAccount();
        $owner = $account->owner;
        $owner->assignRole(PlatformRole::KycManager->value);

        expect($owner->can('requestUpdate', [KycSubmission::class, $account]))->toBeFalse();
    });
});

describe('the endpoint', function () {
    it('opens a round and comes back with a confirmation', function () {
        $account = kycUpdateTestAccount();

        $this->actingAs($this->officer)
            ->from(route('admin.kyc.index'))
            ->post(route('admin.kyc.request-update', $account), [
                'reason' => 'Trade licence expires next month.',
                'instructions' => 'Please upload your renewed trade licence.',
            ])
            ->assertRedirect(route('admin.kyc.index'))
            ->assertSessionHas('success');

        expect($account->kycSubmissions()->where('round', 2)->exists())->toBeTrue();
    });

    it('turns a round already in progress into a form error, not a 500', function () {
        $account = kycUpdateTestAccount();
        KycSubmission::create([
            'business_account_id' => $account->id,
            'status' => KycStatus::Draft,
            'round' => 2,
        ]);

        $this->actingAs($this->officer)
            ->from(route('admin.kyc.index'))
            ->post(route('admin.kyc.request-update', $account), [
                'reason' => 'Reason.',
                'instructions' => 'Instructions.',
            ])
            ->assertSessionHasErrors('reason');
    });

    it('requires both the reason and the instructions', function () {
        $account = kycUpdateTestAccount();

        $this->actingAs($this->officer)
            ->from(route('admin.kyc.index'))
            ->post(route('admin.kyc.request-update', $account), [])
            ->assertSessionHasErrors(['reason', 'instructions']);
    });

    it('refuses a deadline that is not in the future', function () {
        $account = kycUpdateTestAccount();

        $this->actingAs($this->officer)
            ->from(route('admin.kyc.index'))
            ->post(route('admin.kyc.request-update', $account), [
                'reason' => 'Reason.',
                'instructions' => 'Instructions.',
                'deadline' => now()->subDay()->toDateString(),
            ])
            ->assertSessionHasErrors('deadline');
    });

    it('turns away somebody without the permission', function () {
        $account = kycUpdateTestAccount();

        $this->actingAs(User::factory()->staff()->create())
            ->post(route('admin.kyc.request-update', $account), [
                'reason' => 'Reason.',
                'instructions' => 'Instructions.',
            ])
            ->assertForbidden();
    });

    it('uses the account public id, never a database id', function () {
        // §34.2: no database ids in public URLs.
        $account = kycUpdateTestAccount();

        expect(route('admin.kyc.request-update', $account))
            ->toContain($account->public_id)
            ->and(route('admin.kyc.request-update', $account))
            ->not->toContain("/accounts/{$account->id}/");
    });
});
