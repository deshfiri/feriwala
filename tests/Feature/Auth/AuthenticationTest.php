<?php

use App\Domain\Account\Models\AccountInvitation;
use App\Models\User;
use App\Support\Security\LoginThrottle;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;
use Laravel\Passkeys\Contracts\PasskeyLoginResponse;

test('login screen can be rendered', function () {
    $response = $this->get(route('login'));

    $response->assertOk();
});

test('login screen names the account an invitation came from', function () {
    // Someone invited to work in an account usually has no login yet, so the
    // link lands them here first. Without the business name, being asked to
    // sign in is indistinguishable from having clicked the wrong thing.
    $owner = User::factory()
        ->withBusinessAccount(fn ($account) => $account->active()->state(['name' => 'Karim Traders']))
        ->create();

    $invitation = AccountInvitation::factory()->create([
        'business_account_id' => $owner->businessAccount->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $this->get(route('login', ['invitation' => $invitation->token]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/login')
            ->where('staffInvitation.token', $invitation->token)
            ->where('staffInvitation.account', 'Karim Traders')
            ->missing('teamInvitation'),
        );
});

test('users can authenticate using the login screen', function () {
    // A business owner, because the landing depends on who is signing in —
    // see HomeRouteTest. A bare identity with no account and no role has no
    // dashboard to land on, and sending it there was a lockout (D23).
    $user = User::factory()
        ->withBusinessAccount(fn ($account) => $account->active())
        ->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard'));
});

test('passkey login response redirects to the one dashboard', function () {
    $user = User::factory()
        ->withBusinessAccount(fn ($account) => $account->active())
        ->create();

    $request = Request::create(route('login', absolute: false), 'GET', server: [
        'HTTP_ACCEPT' => 'application/json',
    ]);
    $request->setLaravelSession($this->app['session.store']);
    $request->setUserResolver(fn () => $user);

    $jsonResponse = app(PasskeyLoginResponse::class)->toResponse($request);

    // No account segment to fill in: there is one dashboard at one address (D1).
    expect($jsonResponse->getData()->redirect)->toBe(route('dashboard'));
});

test('users with two factor enabled are redirected to two factor challenge', function () {
    if (! Features::canManageTwoFactorAuthentication()) {
        $this->markTestSkipped('Two-factor authentication is not enabled.');
    }

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $user = User::factory()->withTwoFactor()->create();

    $response = $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('two-factor.login'));
    $response->assertSessionHas('login.id', $user->id);
    $this->assertGuest();
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('logout'));

    $this->assertGuest();
    $response->assertRedirect(route('home'));
});

test('users are rate limited', function () {
    // The limits themselves — per identity, per address, the cooldowns and what
    // a successful sign-in clears — are LoginThrottleTest. This one pins that
    // the sign-in route is inside them at all.
    $user = User::factory()->create();

    foreach (range(1, LoginThrottle::IDENTITY_ATTEMPTS) as $ignored) {
        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);
    }

    $this->postJson(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertTooManyRequests();

    $this->assertGuest();
});
