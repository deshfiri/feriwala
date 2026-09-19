<?php

use App\Domain\Account\Actions\SendMobileVerificationCode;
use App\Domain\Account\Actions\VerifyMobile;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Exceptions\ResendTooSoon;
use App\Domain\Account\VerificationCodes;
use App\Integrations\Sms\Contracts\SmsProvider;
use App\Integrations\Sms\Data\SmsMessage;
use App\Integrations\Sms\Data\SmsResult;
use App\Models\User;
use Illuminate\Support\Collection;

beforeEach(function () {
    $this->codes = app(VerificationCodes::class);
});

/**
 * Someone part-way through registration: verified email, unverified mobile, and
 * a business account at the start of the funnel.
 */
function verifyingUser(array $overrides = []): User
{
    $account = testBusinessAccount(AccountStatus::Registered);

    $account->owner->forceFill([
        'mobile' => '+8801712345678',
        'mobile_verified_at' => null,
        'email_verified_at' => now(),
        ...$overrides,
    ])->save();

    return $account->owner->fresh();
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

it('texts a code that verifies the number, in the user\'s language', function (string $locale, string $wording) {
    // Read off the message the way the user does. The template key once went
    // missing and the text said "sms.mobile_verification" — no code at all —
    // while every test that issued its own code kept passing.
    $sent = collect();
    app()->instance(SmsProvider::class, new class($sent) implements SmsProvider
    {
        public function __construct(private Collection $sent) {}

        public function send(SmsMessage $message): SmsResult
        {
            $this->sent->push($message);

            return SmsResult::accepted('test');
        }

        public function balance(): ?string
        {
            return null;
        }

        public function name(): string
        {
            return 'spy';
        }
    });

    $user = verifyingUser(['locale' => $locale]);

    app(SendMobileVerificationCode::class)->handle($user);

    $body = $sent->sole()->body;
    preg_match('/\d{'.VerificationCodes::LENGTH.'}/', $body, $match);

    expect($body)->toContain($wording)
        ->and($match)->not->toBeEmpty()
        ->and(app(VerifyMobile::class)->handle($user, $match[0]))->toBeTrue();
})->with([
    'English' => ['en', 'Your Feriwala verification code is'],
    'Bangla' => ['bn', 'আপনার ফেরিওয়ালা কোড'],
]);

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

    expect($user->businessAccount->fresh()->status)->toBe(AccountStatus::KycPending);
});

it('sends an account still needing email verification to that step instead', function () {
    $user = verifyingUser(['email_verified_at' => null]);
    $code = $this->codes->issue(SendMobileVerificationCode::PURPOSE, $user->mobile);

    app(VerifyMobile::class)->handle($user, $code);

    expect($user->businessAccount->fresh()->status)->toBe(AccountStatus::EmailVerificationPending);
});

it('does not drag a further-along account backwards', function () {
    // Someone who verifies their mobile late — while KYC is already under
    // review — must not be reset to an earlier step.
    $user = verifyingUser();
    $user->businessAccount->forceFill(['status' => AccountStatus::KycUnderReview])->save();
    $code = $this->codes->issue(SendMobileVerificationCode::PURPOSE, $user->mobile);

    app(VerifyMobile::class)->handle($user, $code);

    expect($user->businessAccount->fresh()->status)->toBe(AccountStatus::KycUnderReview)
        ->and($user->fresh()->mobile_verified_at)->not->toBeNull();
});

it('records the status change with a reason', function () {
    $user = verifyingUser();
    $code = $this->codes->issue(SendMobileVerificationCode::PURPOSE, $user->mobile);

    app(VerifyMobile::class)->handle($user, $code);

    expect($user->businessAccount->statusHistory()->first()->reason)->toBe('Mobile number verified.');
});

it('does not let a used code be replayed', function () {
    $user = verifyingUser();
    $code = $this->codes->issue(SendMobileVerificationCode::PURPOSE, $user->mobile);

    app(VerifyMobile::class)->handle($user, $code);

    expect(app(VerifyMobile::class)->handle($user, $code))->toBeFalse();
});
