<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Kyc\Actions\RequestKycUpdate;
use App\Domain\Kyc\Enums\KycConsequence;
use App\Domain\Kyc\Enums\KycRoundPurpose;
use App\Domain\Kyc\Enums\KycStatus;
use App\Domain\Kyc\KycRestrictions;
use App\Domain\Kyc\Models\KycSubmission;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Notification;

/*
 * Asking a trading business to verify again (§7.2, §7.4).
 *
 * The mechanism is the existing `RequestKycUpdate`; what is new is that a
 * round now says **why** it exists and what it costs the business while it is
 * open. These assert both, and that the consequences are enforced in the
 * server-side actions rather than by a hidden button.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    $this->officer = testPlatformStaff(PlatformRole::KycManager);
    $this->account = testBusinessAccount(AccountStatus::Active);

    // An approved first round: re-verification asks a business that has
    // already been through this once.
    $this->approved = KycSubmission::create([
        'business_account_id' => $this->account->id,
        'status' => KycStatus::Approved,
        'round' => 1,
        'reviewed_at' => now(),
    ]);
});

/**
 * @param  array<int, KycConsequence>|null  $consequences
 */
function requireReverification(
    ?array $consequences = null,
    ?CarbonImmutable $deadline = null,
): KycSubmission {
    return app(RequestKycUpdate::class)->handle(
        test()->account,
        test()->officer,
        'Trade licence expired.',
        'Please upload your renewed trade licence.',
        $deadline ?? now()->addDays(14)->toImmutable(),
        null,
        $consequences,
    );
}

describe('opening a re-verification round', function () {
    it('marks the round as a re-verification and leaves the approved one untouched', function () {
        $round = requireReverification();

        expect($round->purpose)->toBe(KycRoundPurpose::Reverification)
            ->and($round->round)->toBe(2)
            ->and($round->status)->toBe(KycStatus::Draft);

        // The previous approval is evidence of a decision and survives intact.
        $this->approved->refresh();

        expect($this->approved->status)->toBe(KycStatus::Approved)
            ->and($this->approved->round)->toBe(1)
            ->and($this->approved->purpose)->toBe(KycRoundPurpose::Onboarding)
            ->and($this->approved->reviewed_at)->not->toBeNull();
    });

    it('does not change the account status — the business keeps trading while it answers', function () {
        requireReverification([KycConsequence::BlockWholesaleOrders]);

        expect($this->account->fresh()->status)->toBe(AccountStatus::Active);
    });

    it('refuses a second open re-verification, at the action and at the database', function () {
        requireReverification();

        expect(fn () => requireReverification())
            ->toThrow(InvalidArgumentException::class, 'already has a KYC round in progress');

        expect(KycSubmission::query()->where('business_account_id', $this->account->id)->count())->toBe(2);
    });

    it('records the purpose and the consequences in the audit, and nothing sensitive', function () {
        requireReverification([KycConsequence::BlockWithdrawals]);

        $entry = AuditLog::query()->where('action', 'kyc.update_requested')->sole();

        expect($entry->after['purpose'])->toBe('reverification')
            ->and($entry->after['consequences'])->toBe(['block_withdrawals']);

        // No document path, file name or identity field in the payload.
        $payload = json_encode($entry->after);

        foreach (['path', 'disk', 'checksum', 'original_name'] as $forbidden) {
            expect($payload)->not->toContain($forbidden);
        }
    });

    it('stores warning-only as no consequences at all', function () {
        // It *is* nothing, operationally, and storing it would trip the
        // "consequences need a deadline" check for no benefit.
        $round = requireReverification([KycConsequence::WarningOnly]);

        expect($round->consequences)->toBeNull()
            ->and($round->consequences())->toBe([]);
    });

    it('still requires an internal reason and instructions', function () {
        expect(fn () => app(RequestKycUpdate::class)->handle(
            $this->account, $this->officer, '  ', 'Upload it.',
        ))->toThrow(InvalidArgumentException::class, 'internal reason');

        expect(fn () => app(RequestKycUpdate::class)->handle(
            $this->account, $this->officer, 'Expired.', '   ',
        ))->toThrow(InvalidArgumentException::class, 'instructions');
    });

    it('refuses consequences with no deadline to hang them off', function () {
        // "Suspend after the deadline" is meaningless without a deadline, and
        // a restricted business is entitled to know by when it must answer.
        expect(fn () => KycSubmission::create([
            'business_account_id' => $this->account->id,
            'status' => KycStatus::Draft,
            'round' => 5,
            'purpose' => KycRoundPurpose::Reverification,
            'consequences' => [KycConsequence::BlockWholesaleOrders->value],
        ]))->toThrow(QueryException::class);
    });

    it('refuses consequences on a round that is not a re-verification', function () {
        expect(fn () => KycSubmission::create([
            'business_account_id' => $this->account->id,
            'status' => KycStatus::Draft,
            'round' => 6,
            'purpose' => KycRoundPurpose::Onboarding,
            'deadline_at' => now()->addDays(7),
            'consequences' => [KycConsequence::BlockWholesaleOrders->value],
        ]))->toThrow(QueryException::class);
    });
});

describe('what the consequences actually restrict', function () {
    it('restricts nothing when the round carries none', function () {
        requireReverification();

        $restrictions = app(KycRestrictions::class);

        expect($restrictions->blocksWholesaleOrders($this->account))->toBeFalse()
            ->and($restrictions->blocksPublishing($this->account))->toBeFalse()
            ->and($restrictions->blocksWithdrawals($this->account))->toBeFalse();
    });

    it('restricts only what was chosen', function () {
        requireReverification([KycConsequence::BlockWholesaleOrders]);

        $restrictions = app(KycRestrictions::class);

        expect($restrictions->blocksWholesaleOrders($this->account))->toBeTrue()
            // Independently configurable: blocking orders is not blocking
            // everything.
            ->and($restrictions->blocksPublishing($this->account))->toBeFalse()
            ->and($restrictions->blocksWithdrawals($this->account))->toBeFalse();
    });

    it('holds suspension back until the deadline actually passes', function () {
        $round = requireReverification([KycConsequence::SuspendAfterDeadline]);

        expect($round->imposes(KycConsequence::SuspendAfterDeadline))->toBeFalse();

        $round->forceFill(['deadline_at' => now()->subDay()])->save();

        expect($round->refresh()->imposes(KycConsequence::SuspendAfterDeadline))->toBeTrue();
    });

    it('stops restricting once the round is approved', function () {
        $round = requireReverification([KycConsequence::BlockWholesaleOrders]);

        expect(app(KycRestrictions::class)->blocksWholesaleOrders($this->account))->toBeTrue();

        $round->forceFill(['status' => KycStatus::Approved, 'reviewed_at' => now()])->save();

        expect(app(KycRestrictions::class)->blocksWholesaleOrders($this->account))->toBeFalse();
    });

    it('stops restricting once the round is withdrawn', function () {
        $round = requireReverification([KycConsequence::BlockPublishing]);

        $round->forceFill([
            'cancelled_at' => now(),
            'cancelled_by' => $this->officer->id,
            'cancellation_reason' => 'Asked in error.',
        ])->save();

        expect(app(KycRestrictions::class)->blocksPublishing($this->account))->toBeFalse();
    });

    it('refuses a half-written cancellation', function () {
        $round = requireReverification();

        expect(fn () => $round->forceFill(['cancelled_at' => now()])->save())
            ->toThrow(QueryException::class);
    });

    it('never tells the business the reviewer\'s internal reason', function () {
        // §7.3: the private assessment is not what the account holder reads.
        requireReverification([KycConsequence::BlockWholesaleOrders]);

        $message = app(KycRestrictions::class)->refusalReason($this->account);

        expect($message)->not->toContain('Trade licence expired.')
            ->and($message)->toContain('verification');
    });
});
