<?php

use App\Domain\Account\Actions\SendMobileVerificationCode;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\AccountDeletionRequest;
use App\Domain\Account\Models\AuthenticatedSession;
use App\Domain\Account\VerificationCodes;
use App\Models\User;
use App\Notifications\Account\PasswordChanged;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\Sanctum;
use PragmaRX\Google2FA\Google2FA;

/*
 * The mobile account module: registration and password recovery (public), then
 * profile, verification, security, notifications and language for the signed-in
 * person -- all reachable before the business is activated.
 */

/**
 * A person who has registered but whose business is nowhere near activation.
 */
function mobileAccountUnactivated(array $user = []): User
{
    $account = testBusinessAccount(AccountStatus::Registered);
    $account->owner->forceFill($user)->save();

    return $account->owner->fresh();
}

function mobileAccountRegistration(array $overrides = []): array
{
    return [
        'name' => 'Rahim Uddin',
        'email' => 'Rahim@Example.test',
        'mobile' => '+8801712345001',
        'password' => testStrongPassword(),
        'password_confirmation' => testStrongPassword(),
        'terms_accepted' => true,
        'privacy_accepted' => true,
        'device_name' => 'Pixel 8',
        ...$overrides,
    ];
}

test('registration options list the pickers and the password policy without a token', function () {
    $this->getJson(route('mobile.v1.register.options', ['ref' => 'ABC123']))
        ->assertOk()
        ->assertJsonPath('referral_code', 'ABC123')
        ->assertJsonPath('staff_invitation', null)
        ->assertJsonStructure(['countries' => [['value', 'label']], 'default_country', 'genders', 'password_rules']);
});

test('registering opens a business account and signs the person straight in', function () {
    Notification::fake();

    $token = $this->postJson(route('mobile.v1.register.store'), mobileAccountRegistration())
        ->assertCreated()
        ->assertJsonPath('two_factor_required', false)
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('user.email', 'rahim@example.test')
        ->assertJsonPath('user.business_account.status', AccountStatus::Registered->value)
        ->json('token');

    $user = User::query()->where('email', 'rahim@example.test')->sole();

    Notification::assertSentTo($user, VerifyEmail::class);

    $this->withToken($token)->getJson(route('mobile.v1.auth.me'))->assertOk();
});

test('registration refuses a taken email, a missing acceptance and a missing device name', function () {
    $existing = User::factory()->create();

    $this->postJson(route('mobile.v1.register.store'), mobileAccountRegistration([
        'email' => $existing->email,
        'terms_accepted' => false,
        'device_name' => null,
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'terms_accepted', 'device_name']);
});

test('a reset link is sent for a known address and the answer is the same for an unknown one', function () {
    Notification::fake();
    $user = User::factory()->create();

    $known = $this->postJson(route('mobile.v1.password.forgot'), ['email' => $user->email])->assertOk()->json('message');
    $unknown = $this->postJson(route('mobile.v1.password.forgot'), ['email' => 'nobody@example.test'])->assertOk()->json('message');

    Notification::assertSentTo($user, ResetPassword::class);
    Notification::assertCount(1);
    expect($known)->toBe($unknown);
});

test('a reset token sets the new password and ends every token', function () {
    Notification::fake();
    $user = User::factory()->create();
    $user->createToken('Old phone');
    $token = Password::broker('users')->createToken($user);

    $this->postJson(route('mobile.v1.password.reset'), [
        'token' => $token,
        'email' => $user->email,
        'password' => testStrongPassword(),
        'password_confirmation' => testStrongPassword(),
    ])->assertOk();

    expect(Hash::check(testStrongPassword(), $user->fresh()->password))->toBeTrue()
        ->and($user->tokens()->count())->toBe(0);
    Notification::assertSentTo($user, PasswordChanged::class);
});

test('a wrong reset token is refused', function () {
    $user = User::factory()->create();

    $this->postJson(route('mobile.v1.password.reset'), [
        'token' => 'not-a-token',
        'email' => $user->email,
        'password' => testStrongPassword(),
        'password_confirmation' => testStrongPassword(),
    ])->assertUnprocessable()->assertJsonValidationErrors('email');
});

test('the account endpoints need a token', function (string $method, string $route) {
    $this->json($method, route($route))->assertUnauthorized();
})->with([
    ['GET', 'mobile.v1.profile.show'],
    ['GET', 'mobile.v1.notifications.index'],
    ['PUT', 'mobile.v1.security.password.update'],
]);

test('a person on a business that is not activated can reach their profile and update it', function () {
    $user = mobileAccountUnactivated();
    Sanctum::actingAs($user);

    $this->getJson(route('mobile.v1.profile.show'))
        ->assertOk()
        ->assertJsonPath('data.id', $user->public_id)
        ->assertJsonPath('deletion_request', null);

    $this->patchJson(route('mobile.v1.profile.update'), ['name' => 'New Name', 'email' => 'changed@example.test'])
        ->assertOk()
        ->assertJsonPath('data.name', 'New Name')
        ->assertJsonPath('data.email_verified', false);

    expect($user->fresh()->email_verified_at)->toBeNull();
});

test('a profile email already taken is refused', function () {
    $other = User::factory()->create();
    Sanctum::actingAs(mobileAccountUnactivated());

    $this->patchJson(route('mobile.v1.profile.update'), ['name' => 'X', 'email' => $other->email])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});

test('the email verification link can be resent only while unverified', function () {
    Notification::fake();
    $user = mobileAccountUnactivated(['email_verified_at' => null]);
    Sanctum::actingAs($user);

    $this->postJson(route('mobile.v1.verification.email.resend'))->assertAccepted();
    Notification::assertSentTo($user, VerifyEmail::class);

    $user->forceFill(['email_verified_at' => now()])->save();

    $this->postJson(route('mobile.v1.verification.email.resend'))
        ->assertConflict()
        ->assertJsonPath('code', 'email_already_verified');
});

test('the mobile number is confirmed with a texted code', function () {
    $user = mobileAccountUnactivated(['mobile' => '+8801712345678', 'mobile_verified_at' => null]);
    Sanctum::actingAs($user);

    $this->getJson(route('mobile.v1.verification.mobile.show'))
        ->assertOk()
        ->assertJsonPath('data.mobile', '+88017****5678')
        ->assertJsonPath('data.required', true)
        ->assertJsonPath('data.code_pending', false);

    $this->postJson(route('mobile.v1.verification.mobile.send'))->assertAccepted();

    $this->postJson(route('mobile.v1.verification.mobile.send'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('resend');

    $this->postJson(route('mobile.v1.verification.mobile.verify'), ['code' => '000000'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');

    $codes = app(VerificationCodes::class);
    $codes->forget(SendMobileVerificationCode::PURPOSE, $user->mobile);
    $code = $codes->issue(SendMobileVerificationCode::PURPOSE, $user->mobile);

    $this->postJson(route('mobile.v1.verification.mobile.verify'), ['code' => $code])->assertOk();

    expect($user->fresh()->mobile_verified_at)->not->toBeNull();

    $this->postJson(route('mobile.v1.verification.mobile.send'))
        ->assertConflict()
        ->assertJsonPath('code', 'nothing_to_verify');
});

test('changing the password needs the current one, ends every other token and tells the person', function () {
    Notification::fake();
    $user = User::factory()->create();
    $other = $user->createToken('Tablet');
    $current = $user->createToken('Pixel 8')->plainTextToken;
    $session = AuthenticatedSession::query()->create([
        'user_id' => $user->id,
        'session_key' => hash('sha256', 'abc'),
        'session_id' => 'abc',
        'device_fingerprint' => 'x',
        'device_label' => 'Chrome on Windows',
        'last_active_at' => now(),
    ]);

    $payload = ['password' => testStrongPassword(), 'password_confirmation' => testStrongPassword()];

    $this->withToken($current)->putJson(route('mobile.v1.security.password.update'), [...$payload, 'current_password' => 'wrong'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('current_password');

    $this->withToken($current)->putJson(route('mobile.v1.security.password.update'), [...$payload, 'current_password' => 'password'])
        ->assertNoContent();

    expect(Hash::check(testStrongPassword(), $user->fresh()->password))->toBeTrue()
        ->and($user->tokens()->pluck('name')->all())->toBe(['Pixel 8'])
        ->and($session->fresh()->ended_reason)->toBe(AuthenticatedSession::ENDED_PASSWORD_CHANGED);
    Notification::assertSentTo($user, PasswordChanged::class);
});

test('two-factor is set up, confirmed, its codes regenerated and then turned off, each with the password', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->postJson(route('mobile.v1.security.two-factor.enable'), ['current_password' => 'wrong'])
        ->assertUnprocessable();

    $setup = $this->postJson(route('mobile.v1.security.two-factor.enable'), ['current_password' => 'password'])
        ->assertCreated()
        ->assertJsonCount(8, 'data.recovery_codes')
        ->json('data');

    $this->getJson(route('mobile.v1.security.show'))
        ->assertJsonPath('data.two_factor_enabled', false)
        ->assertJsonPath('data.two_factor_pending_confirmation', true);

    $this->postJson(route('mobile.v1.security.two-factor.confirm'), ['code' => '000000'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');

    $code = (new Google2FA)->getCurrentOtp($setup['secret_key']);
    $this->postJson(route('mobile.v1.security.two-factor.confirm'), ['code' => $code])
        ->assertOk()
        ->assertJsonPath('data.two_factor_enabled', true);

    $this->postJson(route('mobile.v1.security.two-factor.enable'), ['current_password' => 'password'])
        ->assertConflict();

    $fresh = $this->postJson(route('mobile.v1.security.two-factor.recovery-codes.regenerate'), ['current_password' => 'password'])
        ->assertOk()
        ->assertJsonCount(8, 'data.recovery_codes')
        ->json('data.recovery_codes');
    expect($fresh)->not->toBe($setup['recovery_codes']);

    $this->deleteJson(route('mobile.v1.security.two-factor.disable'), ['current_password' => 'wrong'])->assertUnprocessable();
    $this->deleteJson(route('mobile.v1.security.two-factor.disable'), ['current_password' => 'password'])->assertNoContent();

    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeFalse();
});

test('web sessions are listed and ended only through the caller\'s own', function () {
    $user = User::factory()->create();
    $stranger = User::factory()->create();
    $make = fn (User $owner) => AuthenticatedSession::query()->create([
        'user_id' => $owner->id,
        'session_key' => hash('sha256', fake()->uuid()),
        'device_fingerprint' => 'x',
        'device_label' => 'Firefox on Linux',
        'last_active_at' => now(),
    ]);
    $mine = $make($user);
    $theirs = $make($stranger);

    Sanctum::actingAs($user);

    $this->getJson(route('mobile.v1.security.sessions.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $mine->public_id)
        ->assertJsonMissingPath('data.0.session_id');

    $this->deleteJson(route('mobile.v1.security.sessions.destroy', $theirs->public_id))->assertNotFound();
    expect($theirs->fresh()->hasEnded())->toBeFalse();

    $this->deleteJson(route('mobile.v1.security.sessions.destroy', $mine->public_id))->assertNoContent();
    expect($mine->fresh()->hasEnded())->toBeTrue();
});

test('an account deletion is asked for with the password, shown, and withdrawn', function () {
    $user = mobileAccountUnactivated();
    Sanctum::actingAs($user);

    $this->postJson(route('mobile.v1.account-deletion.store'), ['current_password' => 'wrong', 'reason' => 'No longer trading'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('current_password');

    $this->postJson(route('mobile.v1.account-deletion.store'), ['current_password' => 'password', 'reason' => 'abc'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reason');

    $this->postJson(route('mobile.v1.account-deletion.store'), ['current_password' => 'password', 'reason' => 'No longer trading'])
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonMissingPath('data.user_id');

    $this->getJson(route('mobile.v1.account-deletion.show'))->assertJsonPath('data.reason', 'No longer trading');

    $this->deleteJson(route('mobile.v1.account-deletion.destroy'))->assertNoContent();

    expect(AccountDeletionRequest::query()->where('user_id', $user->id)->sole()->status->value)->toBe('withdrawn');
});

test('notifications list with an unread count and are read only by their owner', function () {
    $user = mobileAccountUnactivated();
    $stranger = User::factory()->create();
    $make = fn (User $owner) => DatabaseNotification::query()->create([
        'id' => fake()->uuid(),
        'type' => 'test',
        'notifiable_type' => User::class,
        'notifiable_id' => $owner->id,
        'data' => ['event' => 'account.activated'],
    ]);
    $mine = $make($user);
    $make($user);
    $theirs = $make($stranger);

    Sanctum::actingAs($user);

    $this->getJson(route('mobile.v1.notifications.index'))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('unread_count', 2)
        ->assertJsonMissingPath('data.0.href');

    $this->postJson(route('mobile.v1.notifications.read', $theirs->id))->assertNotFound();
    $this->postJson(route('mobile.v1.notifications.read', $mine->id))->assertNoContent();
    $this->getJson(route('mobile.v1.notifications.index'))->assertJsonPath('unread_count', 1);

    $this->postJson(route('mobile.v1.notifications.read-all'))->assertNoContent();
    $this->getJson(route('mobile.v1.notifications.index'))->assertJsonPath('unread_count', 0);
    expect($theirs->fresh()->read_at)->toBeNull();
});

test('the language is saved on the account and refused when unknown', function () {
    $user = mobileAccountUnactivated(['locale' => 'en']);
    Sanctum::actingAs($user);

    $this->putJson(route('mobile.v1.locale.update'), ['locale' => 'bn'])
        ->assertOk()
        ->assertJsonPath('data.locale', 'bn');
    expect($user->fresh()->locale)->toBe('bn');

    $this->getJson(route('mobile.v1.locale.show'))->assertJsonPath('data.locale', 'bn');

    $this->putJson(route('mobile.v1.locale.update'), ['locale' => 'fr'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('locale');
});
