<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Kyc\Enums\KycStatus;
use App\Domain\Kyc\Models\KycReview;
use App\Domain\Kyc\Models\KycSubmission;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('is closed to guests', function () {
    $this->get(route('onboarding.status'))->assertRedirect(route('login'));
});

it('shows an unactivated account its progress', function () {
    $user = User::factory()->create(['status' => AccountStatus::KycPending]);

    $this->actingAs($user)
        ->get(route('onboarding.status'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('onboarding/status')
            ->where('progress.current_step', 'kyc')
            ->where('progress.is_complete', false)
            ->where('progress.action.label', 'Submit your KYC documents')
            ->has('progress.steps', 6),
        );
});

it('stays reachable after activation', function () {
    // Someone just activated should see that, not a 404 on the page that has
    // been guiding them.
    $user = User::factory()->create([
        'status' => AccountStatus::Active,
        'activated_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('onboarding.status'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('progress.is_complete', true));
});

it('is not indexable', function () {
    // §34.2: authenticated ERP pages must carry no-index.
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('onboarding.status'))
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');
});

it('never sends the reviewer internal note to the browser', function () {
    // §7.3. The check is on the rendered response, not just the service, so a
    // future prop change cannot leak it silently.
    $user = User::factory()->create(['status' => AccountStatus::KycResubmissionRequired]);

    $submission = KycSubmission::create([
        'user_id' => $user->id,
        'status' => KycStatus::ResubmissionRequired,
        'round' => 1,
    ]);

    KycReview::create([
        'kyc_submission_id' => $submission->id,
        'from_status' => KycStatus::UnderReview,
        'to_status' => KycStatus::ResubmissionRequired,
        'user_visible_feedback' => 'Please upload a clearer photo.',
        'internal_note' => 'Suspected forgery — escalate quietly.',
    ]);

    $response = $this->actingAs($user)->get(route('onboarding.status'));

    $response->assertOk()
        ->assertDontSee('Suspected forgery', escape: false)
        ->assertDontSee('escalate quietly', escape: false)
        ->assertSee('Please upload a clearer photo', escape: false);
});
