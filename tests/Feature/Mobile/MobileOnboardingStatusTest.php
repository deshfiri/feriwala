<?php

use App\Domain\Account\Enums\AccountStatus;
use Laravel\Sanctum\Sanctum;

/*
 * Where an account stands in activation, for the mobile app (§33.4):
 * GET /api/mobile/v1/onboarding. The same funnel the web stepper shows, with
 * the next screen named so the app can route to it.
 */

it('names the stage an unactivated account is at, and the screen to open', function (AccountStatus $status, string $step, string $screen) {
    $account = testBusinessAccount($status);
    $account->owner->forceFill(['email_verified_at' => now(), 'mobile_verified_at' => now()])->save();

    Sanctum::actingAs($account->owner->fresh());

    $this->getJson(route('mobile.v1.onboarding.status'))
        ->assertOk()
        ->assertJsonPath('data.is_complete', false)
        ->assertJsonPath('data.current_step', $step)
        ->assertJsonPath('data.status.value', $status->value)
        ->assertJsonPath('data.action.screen', $screen)
        ->assertJsonStructure(['data' => [
            'is_complete', 'current_step',
            'steps' => [['key', 'label', 'position', 'state']],
            'action', 'blocked_reason', 'feedback',
            'status' => ['value', 'label', 'tone'],
        ]]);
})->with([
    'kyc' => [AccountStatus::KycPending, 'kyc', 'kyc'],
    'package' => [AccountStatus::PackageSelectionPending, 'package', 'packages'],
]);

it('says an active account is complete', function () {
    $account = testBusinessAccount(AccountStatus::Active);

    Sanctum::actingAs($account->owner);

    $this->getJson(route('mobile.v1.onboarding.status'))
        ->assertOk()
        ->assertJsonPath('data.is_complete', true)
        ->assertJsonPath('data.status.value', 'active');
});

it('is closed without a token', function () {
    $this->getJson(route('mobile.v1.onboarding.status'))->assertUnauthorized();
});
