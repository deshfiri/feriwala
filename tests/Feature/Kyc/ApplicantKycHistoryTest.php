<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Kyc\Actions\OpenKycDraft;
use App\Domain\Kyc\Actions\RequestKycUpdate;
use App\Domain\Kyc\Actions\ReviewKyc;
use App\Domain\Kyc\Data\KycDecision;
use App\Domain\Kyc\Enums\KycStatus;
use App\Domain\Kyc\Models\KycDocumentType;
use App\Domain\Kyc\Queries\ApplicantKycHistory;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The applicant's own KYC history (P1-27, §7.3).
 *
 * What matters most here is what never appears. A round carries writing *to*
 * the applicant and writing *about* them; only the first is theirs to read, and
 * a reviewer's private note reaching the person it is about is a disclosure,
 * not a display bug.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/** An account with one reviewed round carrying feedback and an internal note. */
function historyTestAccount(): BusinessAccount
{
    $account = testBusinessAccount();
    $account->owner->forceFill(['country' => 'BD'])->save();

    // Shared across calls: the key is unique, and two accounts are asked for
    // the same catalogue, not for a catalogue each.
    if (! KycDocumentType::query()->where('key', 'national_id')->exists()) {
        KycDocumentType::factory()->key('national_id')->create(['name' => 'National ID']);
    }

    $submission = app(OpenKycDraft::class)->handle($account);
    $submission->forceFill([
        'status' => KycStatus::UnderReview,
        'submitted_at' => now()->subDays(2),
    ])->save();

    app(ReviewKyc::class)->handle($submission, KycDecision::requestResubmission(
        testPlatformStaff(PlatformRole::KycManager)->id,
        userVisibleFeedback: 'Your ID photo is too dark to read.',
        internalNote: 'Suspected reused stock photo — escalate if repeated.',
    ));

    return $account->refresh();
}

describe('what the applicant sees', function () {
    it('lists their rounds with dates and outcome', function () {
        $account = historyTestAccount();

        $rounds = app(ApplicantKycHistory::class)->forAccount($account);

        // One round: a decision does not open the next one. The applicant
        // returning to the form is what does that.
        expect($rounds)->toHaveCount(1);

        $reviewed = collect($rounds)->firstWhere('round', 1);

        expect($reviewed['status'])->toBe(KycStatus::ResubmissionRequired->value)
            ->and($reviewed['status_label'])->not->toBe('')
            ->and($reviewed['opened_at'])->not->toBeNull()
            ->and($reviewed['submitted_at'])->not->toBeNull()
            ->and($reviewed['reviewed_at'])->not->toBeNull();
    });

    it('shows the feedback that was written to them', function () {
        $account = historyTestAccount();

        $rounds = app(ApplicantKycHistory::class)->forAccount($account);
        $reviewed = collect($rounds)->firstWhere('round', 1);

        expect($reviewed['feedback'][0]['feedback'])
            ->toBe('Your ID photo is too dark to read.');
    });

    it('shows what was asked for and whether it was provided', function () {
        $account = historyTestAccount();

        $rounds = app(ApplicantKycHistory::class)->forAccount($account);
        $requirements = collect($rounds)->firstWhere('round', 1)['requirements'];

        expect($requirements)->toHaveCount(1)
            ->and($requirements[0]['name'])->toBe('National ID')
            ->and($requirements[0]['status'])->toBe('outstanding');
    });

    it('shows the deadline and how long is left', function () {
        $account = testBusinessAccount();
        KycDocumentType::factory()->create();

        $submission = app(OpenKycDraft::class)->handle($account);
        $submission->forceFill(['deadline_at' => now()->addDays(5)->endOfDay()])->save();

        $round = app(ApplicantKycHistory::class)->forAccount($account->refresh())[0];

        expect($round['deadline_at'])->not->toBeNull()
            ->and($round['days_remaining'])->toBe(5)
            ->and($round['is_overdue'])->toBeFalse();
    });

    it('tells them a round was requested, and what to do', function () {
        $account = testBusinessAccount();
        KycDocumentType::factory()->create();

        $first = app(OpenKycDraft::class)->handle($account);
        $first->forceFill(['status' => KycStatus::Approved, 'reviewed_at' => now()])->save();

        app(RequestKycUpdate::class)->handle(
            $account,
            testPlatformStaff(PlatformRole::KycManager),
            'Flagged by the sanctions screen.',
            'Please upload your renewed trade licence.',
        );

        $round = app(ApplicantKycHistory::class)->forAccount($account->refresh())[0];

        expect($round['was_requested'])->toBeTrue()
            ->and($round['instructions'])->toBe('Please upload your renewed trade licence.');
    });
});

describe('what the applicant must never see', function () {
    it('carries no internal reviewer note', function () {
        $account = historyTestAccount();

        $payload = json_encode(app(ApplicantKycHistory::class)->forAccount($account));

        expect($payload)->not->toContain('stock photo')
            ->and($payload)->not->toContain('internal_note');
    });

    it('carries no internal reason behind a requested update', function () {
        $account = testBusinessAccount();
        KycDocumentType::factory()->create();

        $first = app(OpenKycDraft::class)->handle($account);
        $first->forceFill(['status' => KycStatus::Approved, 'reviewed_at' => now()])->save();

        app(RequestKycUpdate::class)->handle(
            $account,
            testPlatformStaff(PlatformRole::KycManager),
            'Flagged by the sanctions screen.',
            'Please upload your renewed trade licence.',
        );

        $payload = json_encode(app(ApplicantKycHistory::class)->forAccount($account->refresh()));

        expect($payload)->not->toContain('sanctions')
            ->and($payload)->not->toContain('request_reason');
    });

    it('names no reviewer', function () {
        // The applicant needs to know what was decided, not which member of
        // staff to argue with.
        $account = historyTestAccount();

        $payload = json_encode(app(ApplicantKycHistory::class)->forAccount($account));

        expect($payload)->not->toContain('reviewer_id');
    });

    it('carries no file path, disk or checksum', function () {
        // §7.5 keeps them out of every representation.
        $account = historyTestAccount();

        $payload = json_encode(app(ApplicantKycHistory::class)->forAccount($account));

        expect($payload)->not->toContain('checksum')
            ->and($payload)->not->toContain('"path"')
            ->and($payload)->not->toContain('"disk"');
    });
});

describe('the screen', function () {
    it('shows the signed-in person their own history', function () {
        $account = historyTestAccount();

        $this->actingAs($account->owner)
            ->get(route('kyc.history'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('onboarding/kyc-history')
                ->has('rounds', 1),
            );
    });

    it('never renders the reviewer\'s internal note', function () {
        // Asserted on the response, not the query, because a payload is only
        // safe if it is safe by the time it reaches the browser.
        $account = historyTestAccount();

        $this->actingAs($account->owner)
            ->get(route('kyc.history'))
            ->assertDontSee('stock photo');
    });

    it('has no account identifier to substitute', function () {
        // §31.3: self-scoping is a query concern. The account comes from the
        // signed-in person's membership, never from the URL.
        expect(route('kyc.history', absolute: false))->toBe('/kyc/history');
    });

    it('shows one account nothing of another', function () {
        $mine = historyTestAccount();
        historyTestAccount();

        $this->actingAs($mine->owner)
            ->get(route('kyc.history'))
            ->assertInertia(fn (Assert $page) => $page->has('rounds', 1));
    });

    it('turns away a platform staff member, who has no business account', function () {
        $this->actingAs(User::factory()->staff()->create())
            ->get(route('kyc.history'))
            ->assertForbidden();
    });

    it('shows an applicant with no rounds an empty history rather than an error', function () {
        $account = testBusinessAccount();

        $this->actingAs($account->owner)
            ->get(route('kyc.history'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('rounds', 0));
    });
});
