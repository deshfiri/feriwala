<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Actions\SuspendAccount;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Kyc\Actions\EnforceKycDeadline;
use App\Domain\Kyc\Actions\LiftKycDeadlineRestriction;
use App\Domain\Kyc\Actions\RequestKycUpdate;
use App\Domain\Kyc\Actions\ReviewKyc;
use App\Domain\Kyc\Data\KycDecision;
use App\Domain\Kyc\Enums\KycConsequence;
use App\Domain\Kyc\Enums\KycStatus;
use App\Domain\Kyc\KycRestrictions;
use App\Domain\Kyc\Models\KycSubmission;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

/*
 * Approving a re-verification releases what **that case** imposed, and
 * nothing else (§7.2, §7.4).
 *
 * The dangerous failure here is over-release: an approval that quietly
 * reactivates a business somebody suspended for fraud, or clears a
 * restriction a different policy put there. Every test below is about the
 * boundary.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    $this->officer = testPlatformStaff(PlatformRole::KycManager);
    $this->account = testBusinessAccount(AccountStatus::Active);

    $this->approved = KycSubmission::create([
        'business_account_id' => $this->account->id,
        'status' => KycStatus::Approved,
        'round' => 1,
        'reviewed_at' => now(),
    ]);
});

/** @param array<int, KycConsequence> $consequences */
function releaseTestRound(array $consequences = []): KycSubmission
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

/** Approve a round through the sanctioned decision path. */
function releaseTestApprove(KycSubmission $round): void
{
    $round->forceFill(['status' => KycStatus::Submitted, 'submitted_at' => now()])->save();

    app(ReviewKyc::class)->handle(
        $round->refresh(),
        KycDecision::approve(reviewerId: test()->officer->id),
    );
}

describe('approving releases only this case', function () {
    it('lifts the blocking consequences it imposed', function () {
        $round = releaseTestRound([KycConsequence::BlockNewOrders, KycConsequence::BlockPublishing]);

        expect(app(KycRestrictions::class)->blocksNewOrders($this->account))->toBeTrue();

        releaseTestApprove($round);

        expect(app(KycRestrictions::class)->blocksNewOrders($this->account))->toBeFalse()
            ->and(app(KycRestrictions::class)->blocksPublishing($this->account))->toBeFalse();
    });

    it('leaves an independently suspended account suspended', function () {
        // The whole point. A business stopped for fraud must not walk free by
        // sending in a trade licence.
        $round = releaseTestRound([KycConsequence::BlockNewOrders]);

        app(SuspendAccount::class)->handle(
            account: $this->account,
            decidedBy: testPlatformStaff(PlatformRole::Admin)->id,
            reason: 'Chargeback pattern under investigation.',
        );

        releaseTestApprove($round);

        expect($this->account->fresh()->status)->toBe(AccountStatus::Suspended);
    });

    it('does not reactivate an account the deadline itself suspended', function () {
        // Suspension is never lifted automatically, even when it was this
        // very case that imposed it: restoring the ability to trade is a
        // person's decision (§5.3).
        $round = releaseTestRound([KycConsequence::SuspendAfterDeadline]);
        $round->forceFill(['deadline_at' => now()->subDay()])->save();

        app(EnforceKycDeadline::class)->handle($round->refresh());

        expect($this->account->fresh()->status)->toBe(AccountStatus::Suspended);

        releaseTestApprove($round->refresh());

        expect($this->account->fresh()->status)->toBe(AccountStatus::Suspended);
    });

    it('will not lift a restriction imposed by a round that is still outstanding', function () {
        /*
         * The looseness this test was written to catch: the lift used to ask
         * "does this account have *any* approved round", and nearly every
         * account has one — its onboarding round. A restriction imposed by a
         * later re-verification would have been released while that
         * re-verification was still unanswered.
         */
        $round = releaseTestRound([KycConsequence::SuspendAfterDeadline]);
        $round->forceFill(['deadline_at' => now()->subDay()])->save();

        // Restrict rather than suspend, so the lift has something it could
        // wrongly act on.
        app(SuspendAccount::class); // resolve container once, no side effect
        $this->account->forceFill(['status' => AccountStatus::TemporarilyRestricted])->save();

        expect(app(LiftKycDeadlineRestriction::class)->handle($this->account->refresh()))->toBeFalse()
            ->and($this->account->fresh()->status)->toBe(AccountStatus::TemporarilyRestricted);
    });

    it('is idempotent — approving twice releases once', function () {
        $round = releaseTestRound([KycConsequence::BlockNewOrders]);

        releaseTestApprove($round);

        $before = AuditLog::query()->count();

        // A second decision on a decided round is refused by the state
        // machine; what matters is that nothing further is released or
        // recorded.
        try {
            app(ReviewKyc::class)->handle(
                $round->refresh(),
                KycDecision::approve(reviewerId: $this->officer->id),
            );
        } catch (Throwable) {
            // expected
        }

        expect(AuditLog::query()->count())->toBe($before)
            ->and(app(KycRestrictions::class)->blocksNewOrders($this->account))->toBeFalse();
    });

    it('keeps the whole history rather than editing it', function () {
        $round = releaseTestRound([KycConsequence::BlockNewOrders]);

        releaseTestApprove($round);

        $round->refresh();

        // The consequences the case carried are still on the record; the
        // round simply is not open any more.
        expect($round->consequences)->toBe(['block_new_orders'])
            ->and($round->status)->toBe(KycStatus::Approved)
            ->and($round->reviewed_at)->not->toBeNull()
            ->and($this->approved->refresh()->status)->toBe(KycStatus::Approved);
    });
});
