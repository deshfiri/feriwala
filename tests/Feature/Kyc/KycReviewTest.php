<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Kyc\Actions\ReviewKyc;
use App\Domain\Kyc\Actions\StartKycResubmission;
use App\Domain\Kyc\Actions\SubmitKyc;
use App\Domain\Kyc\Data\KycDecision;
use App\Domain\Kyc\Enums\KycStatus;
use App\Domain\Kyc\Exceptions\KycIncomplete;
use App\Domain\Kyc\KycDocumentStore;
use App\Domain\Kyc\Models\KycDocumentType;
use App\Domain\Kyc\Models\KycSubmission;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake(KycDocumentStore::DISK);

    $this->applicant = User::factory()->create([
        'status' => AccountStatus::KycPending,
        'country' => 'BD',
    ]);

    $this->reviewer = User::factory()->create();

    $this->submission = KycSubmission::create([
        'user_id' => $this->applicant->id,
        'status' => KycStatus::Draft,
        'round' => 1,
    ]);

    $this->nid = KycDocumentType::create([
        'key' => 'national_id',
        'name' => 'National ID',
        'is_required' => true,
        'accepted_mime_types' => ['image/jpeg'],
        'max_size_kb' => 2048,
    ]);
});

function attachNid(): void
{
    app(KycDocumentStore::class)->store(
        test()->submission,
        test()->nid,
        UploadedFile::fake()->image('nid.jpg')->size(100),
    );
}

describe('submitting', function () {
    it('refuses a round missing a required document', function () {
        expect(fn () => app(SubmitKyc::class)->handle($this->submission))
            ->toThrow(KycIncomplete::class, 'National ID');
    });

    it('accepts a complete round and moves the account on', function () {
        attachNid();

        app(SubmitKyc::class)->handle($this->submission);

        expect($this->submission->fresh()->status)->toBe(KycStatus::Submitted)
            ->and($this->submission->fresh()->submitted_at)->not->toBeNull()
            ->and($this->applicant->fresh()->status)->toBe(AccountStatus::KycSubmitted);
    });

    it('ignores a requirement that does not apply to this applicant', function () {
        // §7.2: a trade licence scoped to another country must not block a
        // Bangladeshi applicant.
        $tradeLicence = KycDocumentType::create([
            'key' => 'trade_licence',
            'name' => 'Trade Licence',
            'is_required' => true,
            'accepted_mime_types' => ['application/pdf'],
            'max_size_kb' => 2048,
        ]);
        $tradeLicence->scopes()->create(['scope_type' => 'country', 'scope_value' => 'IN']);

        attachNid();

        app(SubmitKyc::class)->handle($this->submission);

        expect($this->submission->fresh()->status)->toBe(KycStatus::Submitted);
    });

    it('ignores an inactive requirement', function () {
        KycDocumentType::create([
            'key' => 'passport',
            'name' => 'Passport',
            'is_required' => true,
            'is_active' => false,
            'accepted_mime_types' => ['image/jpeg'],
            'max_size_kb' => 2048,
        ]);

        attachNid();

        app(SubmitKyc::class)->handle($this->submission);

        expect($this->submission->fresh()->status)->toBe(KycStatus::Submitted);
    });
});

describe('approving', function () {
    beforeEach(function () {
        attachNid();
        app(SubmitKyc::class)->handle($this->submission);
    });

    it('records the decision and opens package selection', function () {
        app(ReviewKyc::class)->handle(
            $this->submission,
            KycDecision::approve($this->reviewer->id),
        );

        expect($this->submission->fresh()->status)->toBe(KycStatus::Approved)
            ->and($this->applicant->fresh()->status)->toBe(AccountStatus::PackageSelectionPending);
    });

    it('does not activate the account', function () {
        // §5.1, §44: activation also needs payment verification and
        // administrative approval. Clearing KYC opens one gate, not all of them.
        app(ReviewKyc::class)->handle(
            $this->submission,
            KycDecision::approve($this->reviewer->id),
        );

        $applicant = $this->applicant->fresh();

        expect($applicant->status)->not->toBe(AccountStatus::Active)
            ->and($applicant->isActivated())->toBeFalse()
            ->and($applicant->activated_at)->toBeNull();
    });

    it('names the reviewer on the review record', function () {
        $review = app(ReviewKyc::class)->handle(
            $this->submission,
            KycDecision::approve($this->reviewer->id, internalNote: 'Clear scan.'),
        );

        expect($review->reviewer_id)->toBe($this->reviewer->id)
            ->and($review->to_status)->toBe(KycStatus::Approved)
            ->and($review->internal_note)->toBe('Clear scan.');
    });
});

describe('rejecting', function () {
    beforeEach(function () {
        attachNid();
        app(SubmitKyc::class)->handle($this->submission);
    });

    it('demands a reason and applicant-facing feedback', function () {
        // Someone being turned away is entitled to know why.
        expect(fn () => KycDecision::reject($this->reviewer->id, '', 'feedback'))
            ->toThrow(InvalidArgumentException::class)
            ->and(fn () => KycDecision::reject($this->reviewer->id, 'reason', '  '))
            ->toThrow(InvalidArgumentException::class);
    });

    it('moves the account to rejected', function () {
        app(ReviewKyc::class)->handle($this->submission, KycDecision::reject(
            $this->reviewer->id,
            reason: 'Document does not match the applicant.',
            userVisibleFeedback: 'The name on your ID does not match your registration.',
        ));

        expect($this->submission->fresh()->status)->toBe(KycStatus::Rejected)
            ->and($this->applicant->fresh()->status)->toBe(AccountStatus::KycRejected);
    });

    it('keeps the internal note off the account history', function () {
        app(ReviewKyc::class)->handle($this->submission, KycDecision::reject(
            $this->reviewer->id,
            reason: 'Suspected forgery.',
            userVisibleFeedback: 'We could not verify your document.',
            internalNote: 'Third attempt from this device — escalate to fraud.',
        ));

        $history = $this->applicant->statusHistory()->first();

        expect($history->user_visible_note)->toBe('We could not verify your document.')
            ->and($history->internal_note)->toBe('Third attempt from this device — escalate to fraud.');
    });
});

describe('requesting corrections', function () {
    beforeEach(function () {
        attachNid();
        app(SubmitKyc::class)->handle($this->submission);
    });

    it('demands feedback telling the applicant what to fix', function () {
        // Without it they will resubmit the same thing.
        expect(fn () => KycDecision::requestResubmission($this->reviewer->id, ''))
            ->toThrow(InvalidArgumentException::class);
    });

    it('moves the account to resubmission required', function () {
        app(ReviewKyc::class)->handle($this->submission, KycDecision::requestResubmission(
            $this->reviewer->id,
            userVisibleFeedback: 'Please upload a clearer photo of your NID.',
        ));

        expect($this->submission->fresh()->status)->toBe(KycStatus::ResubmissionRequired)
            ->and($this->applicant->fresh()->status)->toBe(AccountStatus::KycResubmissionRequired);
    });
});

describe('resubmission rounds', function () {
    beforeEach(function () {
        attachNid();
        app(SubmitKyc::class)->handle($this->submission);
        app(ReviewKyc::class)->handle($this->submission, KycDecision::requestResubmission(
            $this->reviewer->id,
            userVisibleFeedback: 'Please upload a clearer photo.',
        ));
    });

    it('opens a new round rather than editing the reviewed one', function () {
        $next = app(StartKycResubmission::class)->handle($this->applicant);

        expect($next->round)->toBe(2)
            ->and($next->status)->toBe(KycStatus::Draft)
            ->and($next->id)->not->toBe($this->submission->id);
    });

    it('leaves the reviewed round untouched', function () {
        app(StartKycResubmission::class)->handle($this->applicant);

        // The reviewer's decision must keep describing what they actually saw.
        expect($this->submission->fresh()->status)->toBe(KycStatus::ResubmissionRequired)
            ->and($this->submission->fresh()->documents()->count())->toBe(1);
    });

    it('returns the existing draft instead of creating a second one', function () {
        $first = app(StartKycResubmission::class)->handle($this->applicant);
        $second = app(StartKycResubmission::class)->handle($this->applicant);

        expect($second->id)->toBe($first->id)
            ->and(KycSubmission::where('user_id', $this->applicant->id)->count())->toBe(2);
    });

    it('refuses to resubmit an approved round', function () {
        $approved = KycSubmission::create([
            'user_id' => User::factory()->create()->id,
            'status' => KycStatus::Approved,
            'round' => 1,
        ]);

        expect(fn () => app(StartKycResubmission::class)->handle($approved->user))
            ->toThrow(RuntimeException::class);
    });

    it('carries the deadline across to the new round', function () {
        $this->submission->update(['deadline_at' => now()->addDays(7)]);

        $next = app(StartKycResubmission::class)->handle($this->applicant);

        expect($next->deadline_at)->not->toBeNull();
    });
});

it('keeps the full review history across rounds', function () {
    attachNid();
    app(SubmitKyc::class)->handle($this->submission);
    app(ReviewKyc::class)->handle($this->submission, KycDecision::requestResubmission(
        $this->reviewer->id,
        userVisibleFeedback: 'Clearer photo please.',
    ));

    $round2 = app(StartKycResubmission::class)->handle($this->applicant);
    app(KycDocumentStore::class)->store(
        $round2,
        $this->nid,
        UploadedFile::fake()->image('nid-2.jpg')->size(100),
    );
    app(SubmitKyc::class)->handle($round2);
    app(ReviewKyc::class)->handle($round2, KycDecision::approve($this->reviewer->id));

    expect($this->submission->reviews()->count())->toBe(1)
        ->and($round2->reviews()->count())->toBe(1)
        ->and($this->applicant->fresh()->status)->toBe(AccountStatus::PackageSelectionPending);
});
