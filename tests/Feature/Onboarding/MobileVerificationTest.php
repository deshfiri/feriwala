<?php

use App\Domain\Account\Actions\SendMobileVerificationCode;
use App\Domain\Account\Actions\VerifyMobile;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Exceptions\ResendTooSoon;
use App\Domain\Account\VerificationCodes;
use App\Models\User;

beforeEach(function () {
    $this->codes = app(VerificationCodes::class);
});

function verifyingUser(array $overrides = []): User
{
    return User::factory()->create([
        'status' => AccountStatus::Registered,
        'mobile' => '+8801712345678',
        'mobile_verified_at' => null,
        'email_verified_at' => now(),
        ...$overrides,
    ]);
}

it('sends a code and accepts it', function () {
    $user = verifyingUser();

    app(SendMobileVerificationCode::class)->handle($user);

    // The plain code is never readable after issue, so drive verification the
    // way the user does — by knowing the code. Here the test issues its own.
    $this->codes->forget(SendMobileVerificationCode::PURPOSE, $user->mobile);
    $code = $this->codes->issue(SendMobileVerificationCode::PURPOSE, $user->mobile);

    expect(app(VerifyMobile::class)->handle($user, $code))->toBeTrue()
        ->and($user->fresh()->mobile_verified_at)->not->toBeNull();
});

it('rejects a wrong code and leaves the number unverified', function () {
    $user = verifyingUser();
    $this->codes->issue(SendMobileVerificationCode::PURPOSE, $user->mobile);

    expect(app(VerifyMobile::class)->handle($user, '000000'))->toBeFalse()
        ->and($user->fresh()->mobile_verified_at)->toBeNull();
});

it('throttles resend so the form cannot be used to flood a number', function () {
    $user = verifyingUser();
    $send = app(SendMobileVerificationCode::class);

    $send->handle($user);

    expect(fn () => $send->handle($user))->toThrow(ResendTooSoon::class);
});

it('advances a registered account to KYC once mobile and email are both verified', function () {
    $user = verifyingUser(['email_verified_at' => now()]);
    $code = $this->codes->issue(SendMobileVerificationCode::PURPOSE, $user->mobile);

    app(VerifyMobile::class)->handle($user, $code);

    expect($user->fresh()->status)->toBe(AccountStatus::KycPending);
});

it('sends an account still needing email verification to that step instead', function () {
    $user = verifyingUser(['email_verified_at' => null]);
    $code = $this->codes->issue(SendMobileVerificationCode::PURPOSE, $user->mobile);

    app(VerifyMobile::class)->handle($user, $code);

    expect($user->fresh()->status)->toBe(AccountStatus::EmailVerificationPending);
});

it('does not drag a further-along account backwards', function () {
    // Someone who verifies their mobile late — while KYC is already under
    // review — must not be reset to an earlier step.
    $user = verifyingUser(['status' => AccountStatus::KycUnderReview]);
    $code = $this->codes->issue(SendMobileVerificationCode::PURPOSE, $user->mobile);

    app(VerifyMobile::class)->handle($user, $code);

    expect($user->fresh()->status)->toBe(AccountStatus::KycUnderReview)
        ->and($user->fresh()->mobile_verified_at)->not->toBeNull();
});

it('records the status change with a reason', function () {
    $user = verifyingUser();
    $code = $this->codes->issue(SendMobileVerificationCode::PURPOSE, $user->mobile);

    app(VerifyMobile::class)->handle($user, $code);

    expect($user->statusHistory()->first()->reason)->toBe('Mobile number verified.');
});

it('does not let a used code be replayed', function () {
    $user = verifyingUser();
    $code = $this->codes->issue(SendMobileVerificationCode::PURPOSE, $user->mobile);

    app(VerifyMobile::class)->handle($user, $code);

    expect(app(VerifyMobile::class)->handle($user, $code))->toBeFalse();
});
