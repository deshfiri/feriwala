<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Enums\OnboardingStep;
use App\Domain\Account\OnboardingProgress;
use App\Domain\Kyc\Enums\KycStatus;
use App\Domain\Kyc\Models\KycReview;
use App\Domain\Kyc\Models\KycSubmission;

function progressFor(AccountStatus $status): array
{
    // The funnel belongs to the business, so progress is read from an
    // account rather than from the person signed in.
    return app(OnboardingProgress::class)->for(testBusinessAccount($status));
}

function stateOfStep(array $progress, OnboardingStep $step): string
{
    foreach ($progress['steps'] as $row) {
        if ($row['key'] === $step->value) {
            return $row['state'];
        }
    }

    return 'missing';
}

describe('the stepper (§33.4)', function () {
    it('always shows all six steps', function () {
        expect(progressFor(AccountStatus::Registered)['steps'])->toHaveCount(6);
    });

    it('marks earlier steps done and later ones upcoming', function () {
        $progress = progressFor(AccountStatus::PaymentPending);

        expect(stateOfStep($progress, OnboardingStep::Kyc))->toBe('done')
            ->and(stateOfStep($progress, OnboardingStep::Package))->toBe('done')
            ->and(stateOfStep($progress, OnboardingStep::Payment))->toBe('current')
            ->and(stateOfStep($progress, OnboardingStep::Approval))->toBe('upcoming');
    });

    it('marks everything done once activated', function () {
        $progress = progressFor(AccountStatus::Active);

        expect($progress['is_complete'])->toBeTrue()
            ->and(collect($progress['steps'])->pluck('state')->unique()->all())->toBe(['done']);
    });

    it('collapses the several KYC statuses into one step', function () {
        // A user does not need to know "submitted" from "under review" — both
        // mean "we have your documents, wait".
        foreach ([
            AccountStatus::KycPending,
            AccountStatus::KycSubmitted,
            AccountStatus::KycUnderReview,
        ] as $status) {
            expect(progressFor($status)['current_step'])->toBe(OnboardingStep::Kyc->value);
        }
    });
});

describe('the required action', function () {
    it('names one thing to do, never a list', function () {
        // Someone part-way through signing up needs the next thing to click.
        $action = progressFor(AccountStatus::KycPending)['action'];

        expect($action['label'])->toBe('Submit your KYC documents')
            ->and($action)->toHaveKey('route');
    });

    it('changes with the step', function () {
        expect(progressFor(AccountStatus::PackageSelectionPending)['action']['label'])
            ->toBe('Choose your package')
            ->and(progressFor(AccountStatus::PaymentPending)['action']['label'])
            ->toBe('Complete your payment');
    });

    it('offers nothing to do while waiting on a review', function () {
        // Better than a button that does nothing.
        expect(progressFor(AccountStatus::KycUnderReview)['action'])->toBeNull()
            ->and(progressFor(AccountStatus::ApprovalPending)['action'])->toBeNull();
    });
});

describe('when something is wrong', function () {
    it('marks the step blocked rather than showing a bare error', function () {
        $progress = progressFor(AccountStatus::KycResubmissionRequired);

        expect(stateOfStep($progress, OnboardingStep::Kyc))->toBe('blocked')
            ->and($progress['blocked_reason'])->toBe('Your KYC needs a correction.');
    });

    it('still offers the way forward', function () {
        expect(progressFor(AccountStatus::KycResubmissionRequired)['action']['label'])
            ->toBe('Update your KYC documents');
    });

    it('explains a suspension without offering an action', function () {
        $progress = progressFor(AccountStatus::Suspended);

        expect($progress['blocked_reason'])->toContain('suspended')
            ->and($progress['action'])->toBeNull();
    });
});

describe('reviewer feedback', function () {
    it('shows the applicant-facing message', function () {
        $account = testBusinessAccount(AccountStatus::KycResubmissionRequired);

        $submission = KycSubmission::create([
            'business_account_id' => $account->id,
            'status' => KycStatus::ResubmissionRequired,
            'round' => 1,
        ]);

        KycReview::create([
            'kyc_submission_id' => $submission->id,
            'from_status' => KycStatus::UnderReview,
            'to_status' => KycStatus::ResubmissionRequired,
            'user_visible_feedback' => 'Please upload a clearer photo of your NID.',
            'internal_note' => 'Third attempt — escalate to fraud.',
        ]);

        $progress = app(OnboardingProgress::class)->for($account);

        expect($progress['feedback'])->toBe('Please upload a clearer photo of your NID.');
    });

    it('never leaks the internal note', function () {
        // §7.3: the private note and the applicant's message are different
        // things and must stay that way all the way to the screen.
        $account = testBusinessAccount(AccountStatus::KycResubmissionRequired);

        $submission = KycSubmission::create([
            'business_account_id' => $account->id,
            'status' => KycStatus::ResubmissionRequired,
            'round' => 1,
        ]);

        KycReview::create([
            'kyc_submission_id' => $submission->id,
            'from_status' => KycStatus::UnderReview,
            'to_status' => KycStatus::ResubmissionRequired,
            'user_visible_feedback' => 'Please upload a clearer photo.',
            'internal_note' => 'Suspected forgery — do not tell the applicant.',
        ]);

        $serialised = json_encode(app(OnboardingProgress::class)->for($account));

        expect($serialised)->not->toContain('Suspected forgery')
            ->and($serialised)->not->toContain('do not tell');
    });

    it('shows no feedback when nothing is wrong', function () {
        expect(progressFor(AccountStatus::KycUnderReview)['feedback'])->toBeNull();
    });
});

it('reports the account status for display', function () {
    $progress = progressFor(AccountStatus::KycUnderReview);

    expect($progress['status'])->toBe([
        'value' => 'kyc_under_review',
        'label' => 'KYC under review',
        'tone' => 'info',
    ]);
});
