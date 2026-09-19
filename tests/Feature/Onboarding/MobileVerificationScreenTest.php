<?php

use App\Domain\Account\Actions\SendMobileVerificationCode;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\VerificationCodes;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * An owner at the start of the funnel: email confirmed, mobile not.
 */
function mobileScreenOwner(): User
{
    $account = testBusinessAccount(AccountStatus::Registered);

    $account->owner->forceFill([
        'mobile' => '+8801712345678',
        'mobile_verified_at' => null,
        'email_verified_at' => now(),
    ])->save();

    return $account->owner->fresh();
}

function mobileScreenCodePending(User $user): bool
{
    return app(VerificationCodes::class)->isPending(SendMobileVerificationCode::PURPOSE, (string) $user->mobile);
}

it('is closed to guests', function () {
    $this->get(route('verification.mobile'))->assertRedirect(route('login'));
});

it('opens for an account waiting on its mobile, showing the number masked', function () {
    // Where the stepper's "Verify your mobile number" leads — once a 404.
    $this->actingAs(mobileScreenOwner())
        ->get(route('verification.mobile'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('onboarding/verify-mobile')
            ->where('mobile', '+88017****5678')
            ->where('code_pending', false),
        );
});

it('sends nothing just because the page was opened', function () {
    // A reload or a prefetch must not text somebody.
    $user = mobileScreenOwner();

    $this->actingAs($user)->get(route('verification.mobile'))->assertOk();

    expect(mobileScreenCodePending($user))->toBeFalse();
});

it('sends a code when asked and says where it went', function () {
    $user = mobileScreenOwner();

    $this->actingAs($user)
        ->post(route('verification.mobile.send'))
        ->assertRedirect(route('verification.mobile'))
        ->assertInertiaFlash('toast.message', 'Code sent to +88017****5678.');

    expect(mobileScreenCodePending($user))->toBeTrue();
});

it('asks for a wait rather than sending again straight away', function () {
    $user = mobileScreenOwner();

    $this->actingAs($user)->post(route('verification.mobile.send'));

    $this->actingAs($user)
        ->post(route('verification.mobile.send'))
        ->assertSessionHasErrors([
            'resend' => 'A code was sent a moment ago. Wait a minute before asking for another.',
        ]);
});

it('confirms the number and moves the account on', function () {
    $user = mobileScreenOwner();
    $code = app(VerificationCodes::class)->issue(SendMobileVerificationCode::PURPOSE, $user->mobile);

    $this->actingAs($user)
        ->post(route('verification.mobile.verify'), ['code' => $code])
        ->assertRedirect(route('onboarding.status'))
        ->assertInertiaFlash('toast.message', 'Mobile number verified.');

    expect($user->fresh()->mobile_verified_at)->not->toBeNull()
        ->and($user->businessAccount->fresh()->status)->toBe(AccountStatus::KycPending);
});

it('refuses a wrong code and confirms nothing', function () {
    $user = mobileScreenOwner();
    $code = app(VerificationCodes::class)->issue(SendMobileVerificationCode::PURPOSE, $user->mobile);

    $this->actingAs($user)
        ->post(route('verification.mobile.verify'), ['code' => $code === '000000' ? '111111' : '000000'])
        ->assertSessionHasErrors([
            'code' => 'That code is not right, or it has expired. Check it, or send a new one.',
        ]);

    expect($user->fresh()->mobile_verified_at)->toBeNull()
        ->and($user->businessAccount->fresh()->status)->toBe(AccountStatus::Registered);
});

it('asks for a six-digit code', function (string $code, string $message) {
    $this->actingAs(mobileScreenOwner())
        ->post(route('verification.mobile.verify'), ['code' => $code])
        ->assertSessionHasErrors(['code' => $message]);
})->with([
    'missing' => ['', 'The code field is required.'],
    'too short' => ['12345', 'The code field must be 6 digits.'],
    'not digits' => ['abcdef', 'The code field must be 6 digits.'],
]);

it('sends a number already confirmed back to the stepper without texting it', function () {
    $user = mobileScreenOwner();
    $user->forceFill(['mobile_verified_at' => now()])->save();

    $this->actingAs($user)
        ->get(route('verification.mobile'))
        ->assertRedirect(route('onboarding.status'));

    $this->actingAs($user)
        ->post(route('verification.mobile.send'))
        ->assertRedirect(route('onboarding.status'));

    expect(mobileScreenCodePending($user))->toBeFalse();
});
