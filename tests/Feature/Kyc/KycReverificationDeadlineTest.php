<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Kyc\Actions\CancelKycReverification;
use App\Domain\Kyc\Actions\EnforceKycDeadline;
use App\Domain\Kyc\Actions\RequestKycUpdate;
use App\Domain\Kyc\Enums\KycConsequence;
use App\Domain\Kyc\Enums\KycStatus;
use App\Domain\Kyc\KycRestrictions;
use App\Domain\Kyc\Models\KycSubmission;
use App\Domain\Order\Models\Order;
use App\Models\User;
use App\Notifications\Account\AccountSuspended;
use App\Notifications\Kyc\KycReverificationCancelled;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

/*
 * What happens when a re-verification deadline passes, and what happens when
 * staff withdraw the request instead (§7.2, §7.4).
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    $this->officer = testPlatformStaff(PlatformRole::KycManager);
    $this->account = testBusinessAccount(AccountStatus::Active);

    KycSubmission::create([
        'business_account_id' => $this->account->id,
        'status' => KycStatus::Approved,
        'round' => 1,
        'reviewed_at' => now(),
    ]);
});

/**
 * Its own helper rather than the sibling file's: Pest loads every test file
 * into one global function namespace, so a name shared between files works on
 * a full run and fails the moment either is run alone.
 *
 * @param  array<int, KycConsequence>  $consequences
 */
function kycDeadlineTestRound(array $consequences = []): KycSubmission
{
    return app(RequestKycUpdate::class)->handle(
        test()->account,
        test()->officer,
        'Trade licence expired.',
        'Please upload your renewed trade licence.',
        now()->addDays(7)->toImmutable(),
        null,
        $consequences,
    );
}

/**
 * An overdue re-verification carrying `$consequences`.
 *
 * @param  array<int, KycConsequence>  $consequences
 */
function kycDeadlineTestOverdueRound(array $consequences = []): KycSubmission
{
    $round = kycDeadlineTestRound($consequences);

    // Move the clock rather than the status: the round is untouched, it is
    // simply late.
    $round->forceFill(['deadline_at' => now()->subDay()])->save();

    return $round->refresh();
}

describe('a deadline that passes', function () {
    it('suspends only when that consequence was chosen, through the staff suspension action', function () {
        $round = kycDeadlineTestOverdueRound([KycConsequence::SuspendAfterDeadline]);

        expect(app(EnforceKycDeadline::class)->handle($round))->toBeTrue();

        $this->account->refresh();

        expect($this->account->status)->toBe(AccountStatus::Suspended);

        // The same sanctioned path a person uses: status history, a sensitive
        // audit entry, and the account holder told.
        $entry = AuditLog::query()->where('action', 'account.suspended')->sole();

        expect($entry->actor_id)->toBeNull()
            ->and($entry->actor_type)->toBe('system')
            ->and($entry->is_sensitive)->toBeTrue();

        expect($this->account->statusHistory()
            ->where('to_status', AccountStatus::Suspended)->count())->toBe(1);

        Notification::assertSentTo($this->account->owner, AccountSuspended::class);
    });

    it('leaves a warning-only account trading', function () {
        $round = kycDeadlineTestOverdueRound();

        app(EnforceKycDeadline::class)->handle($round);

        expect($this->account->fresh()->status)->toBe(AccountStatus::Active);

        Notification::assertNotSentTo($this->account->owner, AccountSuspended::class);
    });

    it('does not suspend an account whose case only blocks orders', function () {
        // Independently configurable: blocking new orders is not suspension.
        $round = kycDeadlineTestOverdueRound([KycConsequence::BlockNewOrders]);

        app(EnforceKycDeadline::class)->handle($round);

        expect($this->account->fresh()->status)->toBe(AccountStatus::Active)
            ->and(app(KycRestrictions::class)->blocksNewOrders($this->account))->toBeTrue();
    });

    it('acts exactly once however many times the sweep runs', function () {
        $round = kycDeadlineTestOverdueRound([KycConsequence::SuspendAfterDeadline]);

        expect(app(EnforceKycDeadline::class)->handle($round))->toBeTrue()
            // The claim is an insert against a unique index; the second and
            // third runs find it taken and do nothing.
            ->and(app(EnforceKycDeadline::class)->handle($round->refresh()))->toBeFalse()
            ->and(app(EnforceKycDeadline::class)->handle($round->refresh()))->toBeFalse();

        expect(AuditLog::query()->where('action', 'account.suspended')->count())->toBe(1)
            ->and(AuditLog::query()->where('action', 'kyc.deadline_missed')->count())->toBe(1)
            ->and($this->account->refresh()->statusHistory()
                ->where('to_status', AccountStatus::Suspended)->count())->toBe(1);
    });

    it('leaves existing orders and financial records alone', function () {
        $order = Order::factory()->create(['business_account_id' => $this->account->id]);
        $round = kycDeadlineTestOverdueRound([KycConsequence::SuspendAfterDeadline]);

        app(EnforceKycDeadline::class)->handle($round);

        // Blocking new activity never cancels old activity.
        expect($order->fresh())->not->toBeNull()
            ->and($order->fresh()->status)->toBe($order->status)
            ->and($order->fresh()->payment_id)->toBe($order->payment_id);
    });

    it('stamps when the consequences were applied', function () {
        $round = kycDeadlineTestOverdueRound([KycConsequence::BlockPublishing]);

        app(EnforceKycDeadline::class)->handle($round);

        expect($round->refresh()->consequences_applied_at)->not->toBeNull();
    });
});

/*
 * The action has been able to withdraw a round since it was written; for a
 * while nothing could reach it. An undo that exists only in the domain layer
 * leaves staff with the two exits the action was built to avoid — approving a
 * round nobody submitted, or rejecting a business that did nothing wrong.
 */
describe('the withdraw endpoint', function () {
    it('withdraws an unanswered round and lifts what it imposed', function () {
        $round = kycDeadlineTestRound([KycConsequence::BlockNewOrders]);

        $this->actingAs($this->officer)
            ->from(route('admin.kyc.index'))
            ->post(route('admin.kyc.withdraw', $round), [
                'reason' => 'Licence arrived by email.',
            ])
            ->assertRedirect(route('admin.kyc.index'))
            ->assertSessionHas('success');

        expect($round->fresh()->cancelled_at)->not->toBeNull()
            ->and(app(KycRestrictions::class)->blocksNewOrders($this->account))->toBeFalse();
    });

    it('requires a reason', function () {
        $round = kycDeadlineTestRound([KycConsequence::BlockNewOrders]);

        $this->actingAs($this->officer)
            ->from(route('admin.kyc.index'))
            ->post(route('admin.kyc.withdraw', $round), [])
            ->assertSessionHasErrors('reason');

        expect($round->fresh()->cancelled_at)->toBeNull();
    });

    it('turns an already-withdrawn round into a form error, not a 500', function () {
        // Two staff reaching the same round is ordinary, not exceptional.
        $round = kycDeadlineTestRound([KycConsequence::BlockNewOrders]);

        app(CancelKycReverification::class)->handle($round, $this->officer, 'First.');

        $this->actingAs($this->officer)
            ->from(route('admin.kyc.index'))
            ->post(route('admin.kyc.withdraw', $round->fresh()), [
                'reason' => 'Second.',
            ])
            ->assertSessionHasErrors('reason');

        expect($round->fresh()->cancellation_reason)->toBe('First.');
    });

    it('refuses to withdraw a round the business has already submitted', function () {
        // Someone has done the work and is owed a decision.
        $round = kycDeadlineTestRound([KycConsequence::BlockNewOrders]);
        $round->forceFill(['status' => KycStatus::Submitted, 'submitted_at' => now()])->save();

        $this->actingAs($this->officer)
            ->from(route('admin.kyc.index'))
            ->post(route('admin.kyc.withdraw', $round->fresh()), [
                'reason' => 'Changed our mind.',
            ])
            ->assertSessionHasErrors('reason');

        expect($round->fresh()->cancelled_at)->toBeNull();
    });

    it('turns away somebody without the verify permission', function () {
        $round = kycDeadlineTestRound([KycConsequence::BlockNewOrders]);

        $this->actingAs(User::factory()->staff()->create())
            ->post(route('admin.kyc.withdraw', $round), ['reason' => 'No.'])
            ->assertForbidden();

        expect($round->fresh()->cancelled_at)->toBeNull();
    });

    it('uses the round public id, never a database id', function () {
        $round = kycDeadlineTestRound();

        expect(route('admin.kyc.withdraw', $round))
            ->toContain($round->public_id)
            ->and(route('admin.kyc.withdraw', $round))
            ->not->toContain("/kyc/{$round->id}/");
    });
});

describe('withdrawing a request nobody answered', function () {
    it('marks it cancelled, keeps it in history, and lifts its restrictions', function () {
        $round = kycDeadlineTestRound([KycConsequence::BlockNewOrders]);

        expect(app(KycRestrictions::class)->blocksNewOrders($this->account))->toBeTrue();

        app(CancelKycReverification::class)->handle($round, $this->officer, 'Licence arrived by email.');

        $round->refresh();

        expect($round->cancelled_at)->not->toBeNull()
            ->and($round->cancelled_by)->toBe($this->officer->id)
            ->and($round->cancellation_reason)->toBe('Licence arrived by email.')
            // Kept, not deleted: it is evidence that we asked.
            ->and(KycSubmission::query()->whereKey($round->id)->exists())->toBeTrue()
            ->and(app(KycRestrictions::class)->blocksNewOrders($this->account))->toBeFalse();

        Notification::assertSentTo($this->account->owner, KycReverificationCancelled::class);
    });

    it('records who withdrew it and what it lifted, with nothing sensitive', function () {
        $round = kycDeadlineTestRound([KycConsequence::BlockWithdrawals]);

        app(CancelKycReverification::class)->handle($round, $this->officer, 'Asked in error.');

        $entry = AuditLog::query()->where('action', 'kyc.reverification_cancelled')->sole();

        expect($entry->actor_id)->toBe($this->officer->id)
            ->and($entry->reason)->toBe('Asked in error.')
            ->and($entry->after['consequences_lifted'])->toBe(['block_withdrawals']);
    });

    it('needs a reason', function () {
        $round = kycDeadlineTestRound();

        expect(fn () => app(CancelKycReverification::class)->handle($round, $this->officer, '   '))
            ->toThrow(InvalidArgumentException::class, 'needs a reason');

        expect($round->refresh()->cancelled_at)->toBeNull();
    });

    it('refuses to withdraw a round the business has already submitted', function () {
        // Someone has done the work and is owed a decision, not a withdrawal.
        $round = kycDeadlineTestRound();
        $round->forceFill(['status' => KycStatus::Submitted, 'submitted_at' => now()])->save();

        expect(fn () => app(CancelKycReverification::class)->handle($round->refresh(), $this->officer, 'Never mind.'))
            ->toThrow(InvalidArgumentException::class, 'already submitted');
    });

    it('refuses a second withdrawal', function () {
        $round = kycDeadlineTestRound();

        app(CancelKycReverification::class)->handle($round, $this->officer, 'Asked in error.');

        expect(fn () => app(CancelKycReverification::class)->handle($round->refresh(), $this->officer, 'Again.'))
            ->toThrow(InvalidArgumentException::class, 'already been withdrawn');

        expect(AuditLog::query()->where('action', 'kyc.reverification_cancelled')->count())->toBe(1);
    });

    it('lets a fresh request be opened once the old one is withdrawn', function () {
        // The partial unique index allows one *open* case; a withdrawn one is
        // not open, so staff are not locked out by their own mistake.
        $round = kycDeadlineTestRound();

        app(CancelKycReverification::class)->handle($round, $this->officer, 'Wrong document asked for.');

        $replacement = kycDeadlineTestRound([KycConsequence::BlockPublishing]);

        expect($replacement->round)->toBe(3)
            ->and(app(KycRestrictions::class)->blocksPublishing($this->account))->toBeTrue();
    });
});
