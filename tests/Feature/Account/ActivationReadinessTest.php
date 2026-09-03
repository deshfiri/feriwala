<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Actions\ActivateAccount;
use App\Domain\Account\Actions\EvaluateActivationReadiness;
use App\Domain\Account\Actions\RequestKycResubmission;
use App\Domain\Account\Actions\SuspendAccount;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Exceptions\ActivationBlocked;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Kyc\Enums\KycStatus;
use App\Domain\Kyc\Models\KycSubmission;
use App\Support\StateMachine\Exceptions\IllegalStateTransition;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();

    $this->seed(RolesAndPermissionsSeeder::class);

    $this->approver = testPlatformStaff(PlatformRole::Admin);

    // Sitting one step short of the gate: verified, and waiting on KYC and
    // payment, which is where the orchestration has something to do.
    $this->applicant = testBusinessAccount(AccountStatus::PaymentVerificationPending);
});

function approveApplicantKyc(): KycSubmission
{
    return KycSubmission::create([
        'business_account_id' => test()->applicant->id,
        'status' => KycStatus::Approved,
        'round' => 1,
        'reviewed_at' => now(),
    ]);
}

function settleApplicantActivationPayment(): Payment
{
    return Payment::create([
        'business_account_id' => test()->applicant->id,
        'purpose' => PaymentPurpose::Activation,
        'status' => PaymentStatus::Paid,
        'amount_minor' => 600000,
        'currency_code' => 'BDT',
        'completed_at' => now(),
    ]);
}

function evaluateApplicantReadiness(): bool
{
    return app(EvaluateActivationReadiness::class)->handle(test()->applicant);
}

describe('reaching the gate', function () {
    it('does nothing while a requirement is outstanding', function () {
        approveApplicantKyc();

        expect(evaluateApplicantReadiness())->toBeFalse()
            ->and($this->applicant->fresh()->status)
            ->toBe(AccountStatus::PaymentVerificationPending)
            ->and($this->applicant->fresh()->approval_pending_at)->toBeNull();
    });

    it('moves the account to the gate when the last requirement lands', function () {
        approveApplicantKyc();
        settleApplicantActivationPayment();

        expect(evaluateApplicantReadiness())->toBeTrue()
            ->and($this->applicant->fresh()->status)->toBe(AccountStatus::ApprovalPending)
            ->and($this->applicant->fresh()->approval_pending_at)->not->toBeNull();
    });

    it('records when the account became ready, not when it registered', function () {
        // Someone who registered months ago but finished paying today has been
        // waiting on us for a day, not for months.
        $this->applicant->forceFill(['created_at' => now()->subMonths(4)])->save();

        approveApplicantKyc();
        settleApplicantActivationPayment();
        evaluateApplicantReadiness();

        expect($this->applicant->fresh()->approval_pending_at->isToday())->toBeTrue();
    });

    it('is idempotent', function () {
        // A gateway sends the same IPN several times. Three status changes for
        // one payment would make the account's history unreadable.
        approveApplicantKyc();
        settleApplicantActivationPayment();

        evaluateApplicantReadiness();
        $stampedAt = $this->applicant->fresh()->approval_pending_at;

        evaluateApplicantReadiness();
        evaluateApplicantReadiness();

        expect($this->applicant->fresh()->approval_pending_at->equalTo($stampedAt))->toBeTrue()
            ->and($this->applicant->statusHistory()
                ->where('to_status', AccountStatus::ApprovalPending)
                ->count())->toBe(1);
    });

    it('leaves an already active account alone', function () {
        approveApplicantKyc();
        settleApplicantActivationPayment();
        evaluateApplicantReadiness();

        app(ActivateAccount::class)->handle($this->applicant, $this->approver->id);

        expect(evaluateApplicantReadiness())->toBeFalse()
            ->and($this->applicant->fresh()->status)->toBe(AccountStatus::Active);
    });
});

describe('a requirement reversed after reaching the gate', function () {
    beforeEach(function () {
        approveApplicantKyc();
        settleApplicantActivationPayment();
        evaluateApplicantReadiness();
    });

    it('takes the account back out of the queue', function () {
        Payment::where('business_account_id', $this->applicant->id)
            ->update(['status' => PaymentStatus::Refunded]);

        expect(evaluateApplicantReadiness())->toBeFalse()
            ->and($this->applicant->fresh()->approval_pending_at)->toBeNull();
    });

    it('does not leave a stale readiness time behind', function () {
        // Otherwise an account that dropped out in March and returns in June
        // sorts ahead of everyone, as though it had been waiting all along.
        $this->applicant->forceFill([
            'approval_pending_at' => now()->subMonths(3),
        ])->save();

        KycSubmission::where('business_account_id', $this->applicant->id)
            ->update(['status' => KycStatus::Rejected]);

        evaluateApplicantReadiness();

        expect($this->applicant->fresh()->approval_pending_at)->toBeNull();

        // Back in, with today's date rather than March's.
        KycSubmission::where('business_account_id', $this->applicant->id)
            ->update(['status' => KycStatus::Approved]);

        evaluateApplicantReadiness();

        expect($this->applicant->fresh()->approval_pending_at->isToday())->toBeTrue();
    });

    it('refuses activation even though the account is still at the gate', function () {
        // The queue is a view and can be stale; the action re-checks under a row
        // lock, which is what actually decides.
        Payment::where('business_account_id', $this->applicant->id)
            ->update(['status' => PaymentStatus::Refunded]);

        expect(fn () => app(ActivateAccount::class)
            ->handle($this->applicant, $this->approver->id))
            ->toThrow(ActivationBlocked::class);

        expect($this->applicant->fresh()->status)->toBe(AccountStatus::ApprovalPending);
    });
});

describe('two reviewers deciding at once', function () {
    beforeEach(function () {
        approveApplicantKyc();
        settleApplicantActivationPayment();
        evaluateApplicantReadiness();

        $this->second = testPlatformStaff(PlatformRole::Admin);
    });

    it('does not activate an account the other reviewer just suspended', function () {
        // Both loaded the same queue and pressed a different button. The
        // suspension lands first; the approval is working from a view that is
        // now stale, and must not let the account through anyway.
        app(SuspendAccount::class)->handle(
            account: $this->applicant,
            decidedBy: $this->approver->id,
            reason: 'Documents belong to someone else.',
        );

        expect(fn () => app(ActivateAccount::class)
            ->handle($this->applicant, $this->second->id))
            ->toThrow(ActivationBlocked::class, 'Suspended');

        expect($this->applicant->fresh()->status)->toBe(AccountStatus::Suspended)
            ->and($this->applicant->statusHistory()
                ->where('to_status', AccountStatus::Active)
                ->count())->toBe(0);
    });

    it('treats a suspension after activation as a new decision, not a stale one', function () {
        // The other order is not a race the machine should refuse: §5.3 allows
        // an active account to be suspended, and a reviewer who decides that
        // after activation is making a fresh decision. What must not happen is
        // the account ending up active *and* the suspension being lost.
        app(ActivateAccount::class)->handle($this->applicant, $this->approver->id);

        app(SuspendAccount::class)->handle(
            account: $this->applicant,
            decidedBy: $this->second->id,
            reason: 'Documents belong to someone else.',
        );

        expect($this->applicant->fresh()->status)->toBe(AccountStatus::Suspended)
            ->and($this->applicant->statusHistory()->count())->toBeGreaterThanOrEqual(2);
    });

    it('lets only one of two declines win', function () {
        app(SuspendAccount::class)->handle(
            account: $this->applicant,
            decidedBy: $this->approver->id,
            reason: 'Documents belong to someone else.',
        );

        expect(fn () => app(RequestKycResubmission::class)->handle(
            account: $this->applicant,
            decidedBy: $this->second->id,
            reason: 'Licence expired.',
            feedback: 'Please upload a current licence.',
        ))->toThrow(IllegalStateTransition::class);

        expect($this->applicant->fresh()->status)->toBe(AccountStatus::Suspended);
    });

    it('leaves nothing behind from the decision that lost', function () {
        // The losing attempt must not have written a history row, an audit
        // entry, or a subscription change — the whole decision is one
        // transaction or it is nothing.
        app(SuspendAccount::class)->handle(
            account: $this->applicant,
            decidedBy: $this->approver->id,
            reason: 'Documents belong to someone else.',
        );

        $historyBefore = $this->applicant->statusHistory()->count();

        try {
            app(RequestKycResubmission::class)->handle(
                account: $this->applicant,
                decidedBy: $this->second->id,
                reason: 'Licence expired.',
                feedback: 'Please upload a current licence.',
            );
        } catch (IllegalStateTransition) {
            // expected
        }

        expect($this->applicant->statusHistory()->count())->toBe($historyBefore);
    });

    it('activates once when two approvals arrive together', function () {
        app(ActivateAccount::class)->handle($this->applicant, $this->approver->id);

        expect(fn () => app(ActivateAccount::class)
            ->handle($this->applicant, $this->second->id))
            ->toThrow(ActivationBlocked::class, 'already active');

        expect($this->applicant->statusHistory()
            ->where('to_status', AccountStatus::Active)
            ->count())->toBe(1);
    });
});
