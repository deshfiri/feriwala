<?php

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Sanctum\Sanctum;

function mobileLogin(User $user, array $overrides = []): Illuminate\Testing\TestResponse
{
    return test()->postJson(route('mobile.v1.auth.login'), [
        'email' => $user->email,
        'password' => 'password',
        'device_name' => 'Pixel 8',
        ...$overrides,
    ]);
}

test('a person signs in and gets a bearer token that works', function () {
    $user = User::factory()->create();

    $token = mobileLogin($user)
        ->assertOk()
        ->assertJsonPath('two_factor_required', false)
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('user.id', $user->public_id)
        ->assertJsonMissingPath('user.password')
        ->json('token');

    $this->withToken($token)
        ->getJson(route('mobile.v1.auth.me'))
        ->assertOk()
        ->assertJsonPath('data.email', $user->email);
});

test('a wrong password is refused and counts towards the throttle', function () {
    $user = User::factory()->create();

    mobileLogin($user, ['password' => 'wrong'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');

    expect($user->tokens()->count())->toBe(0);
});

test('too many failed sign-ins are answered 429', function () {
    $user = User::factory()->create();

    foreach (range(1, 5) as $attempt) {
        mobileLogin($user, ['password' => 'wrong'])->assertUnprocessable();
    }

    mobileLogin($user)->assertStatus(429);

    RateLimiter::clear('login:identity:'.hash('sha256', mb_strtolower($user->email)));
});

test('a suspended login is refused and never gets a token', function () {
    $user = User::factory()->suspendedIdentity()->create();

    mobileLogin($user)->assertForbidden()->assertJsonPath('code', 'account_unavailable');

    expect($user->tokens()->count())->toBe(0);
});

test('suspending a person revokes the token they already hold', function () {
    $user = User::factory()->create();
    $token = mobileLogin($user)->json('token');

    $user->forceFill(['identity_status' => App\Domain\Account\Enums\UserStatus::Suspended])->save();

    $this->withToken($token)
        ->getJson(route('mobile.v1.auth.me'))
        ->assertForbidden()
        ->assertJsonPath('code', 'account_unavailable');

    expect($user->tokens()->count())->toBe(0);
});

test('a request without a token is unauthenticated and gets JSON, not a redirect', function () {
    $this->getJson(route('mobile.v1.auth.me'))->assertUnauthorized();
});

test('two-factor accounts get a challenge instead of a token', function () {
    $user = User::factory()->withTwoFactor()->create();

    $challenge = mobileLogin($user)
        ->assertStatus(202)
        ->assertJsonPath('two_factor_required', true)
        ->assertJsonMissingPath('token')
        ->json('challenge_token');

    expect($challenge)->toBeString()->and($user->tokens()->count())->toBe(0);
});

test('a correct second factor completes the sign-in and the challenge is single use', function () {
    $user = User::factory()->withTwoFactor()->create();
    $challenge = mobileLogin($user)->json('challenge_token');

    $this->mock(TwoFactorAuthenticationProvider::class)
        ->shouldReceive('verify')->once()->andReturn(true);

    $payload = ['challenge_token' => $challenge, 'code' => '123456', 'device_name' => 'Pixel 8'];

    $this->postJson(route('mobile.v1.auth.two-factor-challenge'), $payload)
        ->assertOk()
        ->assertJsonPath('user.id', $user->public_id)
        ->assertJsonStructure(['token']);

    $this->postJson(route('mobile.v1.auth.two-factor-challenge'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('challenge_token');
});

test('a wrong second-factor code is refused and issues nothing', function () {
    $user = User::factory()->withTwoFactor()->create();
    $challenge = mobileLogin($user)->json('challenge_token');

    $this->mock(TwoFactorAuthenticationProvider::class)
        ->shouldReceive('verify')->andReturn(false);

    $this->postJson(route('mobile.v1.auth.two-factor-challenge'), [
        'challenge_token' => $challenge,
        'code' => '000000',
        'device_name' => 'Pixel 8',
    ])->assertUnprocessable()->assertJsonValidationErrors('code');

    expect($user->tokens()->count())->toBe(0);
});

test('a recovery code signs in once and is then spent', function () {
    $user = User::factory()->withTwoFactor()->create();

    $first = mobileLogin($user)->json('challenge_token');

    $this->postJson(route('mobile.v1.auth.two-factor-challenge'), [
        'challenge_token' => $first,
        'recovery_code' => 'recovery-code-1',
        'device_name' => 'Pixel 8',
    ])->assertOk();

    $second = mobileLogin($user, ['device_name' => 'Tablet'])->json('challenge_token');

    $this->postJson(route('mobile.v1.auth.two-factor-challenge'), [
        'challenge_token' => $second,
        'recovery_code' => 'recovery-code-1',
        'device_name' => 'Tablet',
    ])->assertUnprocessable()->assertJsonValidationErrors('recovery_code');
});

test('signing out revokes only the current device token', function () {
    $user = User::factory()->create();
    $phone = mobileLogin($user)->json('token');
    $tablet = mobileLogin($user, ['device_name' => 'Tablet'])->json('token');

    $this->withToken($phone)->postJson(route('mobile.v1.auth.logout'))->assertNoContent();

    expect($user->tokens()->pluck('name')->all())->toBe(['Tablet']);

    $this->app['auth']->forgetGuards();
    $this->withToken($tablet)->getJson(route('mobile.v1.auth.me'))->assertOk();
});

test('signing in again on the same device replaces its token', function () {
    $user = User::factory()->create();

    mobileLogin($user)->assertOk();
    mobileLogin($user)->assertOk();

    expect($user->tokens()->count())->toBe(1);
});

test('a device can be revoked by its reference, and only by its owner', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $token = mobileLogin($user)->json('token');
    mobileLogin($user, ['device_name' => 'Tablet']);
    mobileLogin($other, ['device_name' => 'Other phone']);

    $devices = $this->withToken($token)->getJson(route('mobile.v1.auth.devices'))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->json('data');

    $tablet = collect($devices)->firstWhere('name', 'Tablet');

    expect($tablet['is_current'])->toBeFalse()->and($tablet)->not->toHaveKey('id');

    $this->withToken($token)->deleteJson(route('mobile.v1.auth.devices.revoke', 'not-a-device'))->assertNotFound();
    $this->withToken($token)->deleteJson(route('mobile.v1.auth.devices.revoke', $tablet['reference']))->assertNoContent();

    expect($user->tokens()->pluck('name')->all())->toBe(['Pixel 8'])
        ->and($other->tokens()->count())->toBe(1);
});

test('the response follows Accept-Language', function () {
    $user = User::factory()->create();

    mobileLogin($user, ['password' => 'wrong'])->assertHeader('Content-Language', 'en');

    $this->withHeader('Accept-Language', 'bn')
        ->postJson(route('mobile.v1.auth.login'), ['email' => $user->email, 'password' => 'wrong', 'device_name' => 'x'])
        ->assertHeader('Content-Language', 'bn');
});

test('a business account that is not activated is told so in JSON', function () {
    $account = testBusinessAccount(App\Domain\Account\Enums\AccountStatus::PaymentVerificationPending);

    Route::middleware(['auth:sanctum', 'mobile.access', 'mobile.activated'])
        ->get('api/mobile/v1/_probe', fn () => ['ok' => true]);

    Sanctum::actingAs($account->owner);

    $this->getJson('api/mobile/v1/_probe')
        ->assertForbidden()
        ->assertJsonPath('code', 'account_not_activated');
});
