<?php

use App\Models\User;
use App\Support\Security\LoginThrottle;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
 * Login attempt limits and brute-force protection (P1-14, §6).
 *
 * Two limits, because the same attacker looks like two different things. One
 * person's password guessed over and over is a per-identity problem; one
 * password tried against a thousand accounts never trips a per-identity limit
 * at all, because each account sees a single failure.
 */

/**
 * One failed sign-in, from a stated address.
 *
 * Prefixed, because Pest loads every test file into one global function
 * namespace and a bare `failure()` would collide with the next person's.
 */
function loginThrottleFailure(TestCase $test, string $email, string $address = '127.0.0.1'): TestResponse
{
    return $test->withServerVariables(['REMOTE_ADDR' => $address])
        ->post(route('login.store'), [
            'email' => $email,
            'password' => 'definitely-not-the-password',
        ]);
}

/**
 * A sign-in with the factory's real password, from a stated address.
 */
function loginThrottleAttempt(TestCase $test, string $email, string $address = '127.0.0.1'): TestResponse
{
    return $test->withServerVariables(['REMOTE_ADDR' => $address])
        ->post(route('login.store'), [
            'email' => $email,
            'password' => 'password',
        ]);
}

describe('guessing one password', function () {
    it('is refused after the configured number of failures', function () {
        $user = User::factory()->create();

        foreach (range(1, LoginThrottle::IDENTITY_ATTEMPTS) as $ignored) {
            loginThrottleFailure($this, $user->email)
                ->assertSessionHasErrors(['email' => trans('auth.failed')]);
        }

        // The point is not that a sixth guess fails — a wrong password always
        // fails. It is that the *right* password no longer gets through, so
        // there is nothing left to learn from another attempt.
        loginThrottleAttempt($this, $user->email);

        $this->assertGuest();
    });

    it('lets go on its own once the cooldown has passed', function () {
        // A cooldown, not a lock. Nothing here needs an administrator to undo
        // it, because a lock an attacker can trigger against any address they
        // know is a way of switching people's accounts off (§6, P1-17).
        $user = User::factory()->create();

        foreach (range(1, LoginThrottle::IDENTITY_ATTEMPTS) as $ignored) {
            loginThrottleFailure($this, $user->email);
        }

        loginThrottleAttempt($this, $user->email);
        $this->assertGuest();

        $this->travel(LoginThrottle::IDENTITY_DECAY_SECONDS + 1)->seconds();

        loginThrottleAttempt($this, $user->email);
        $this->assertAuthenticatedAs($user);
    });

    it('counts one address however it happens to be typed', function () {
        /*
         * Otherwise capitalisation is a way round the limit: five tries as
         * `karim@`, five more as `Karim@`, and so on for as many casings as the
         * address has letters.
         */
        $user = User::factory()->create(['email' => 'karim@example.com']);

        loginThrottleFailure($this, 'Karim@Example.com');
        loginThrottleFailure($this, 'KARIM@EXAMPLE.COM');
        loginThrottleFailure($this, '  karim@example.com  ');
        loginThrottleFailure($this, 'karim@Example.com');
        loginThrottleFailure($this, 'karim@example.com');

        loginThrottleAttempt($this, 'karim@example.com');

        $this->assertGuest();
    });

    it('follows the identity rather than the address it is tried from', function () {
        // A botnet is the ordinary way to spread guesses across addresses, so a
        // limit keyed on the pair of them is one that a rented proxy list turns
        // off entirely.
        $user = User::factory()->create();

        foreach (['203.0.113.1', '203.0.113.2', '198.51.100.7', '192.0.2.9', '203.0.113.44'] as $address) {
            loginThrottleFailure($this, $user->email, $address);
        }

        loginThrottleAttempt($this, $user->email, '198.51.100.200');

        $this->assertGuest();
    });
});

describe('spraying from one address', function () {
    it('counts failures across every identity that address tries', function () {
        /*
         * Thirty accounts tried once each is thirty first attempts, and no
         * per-identity limit has anything to say about it. This is the limit
         * that does.
         */
        $address = '203.0.113.10';

        foreach (range(1, LoginThrottle::ADDRESS_ATTEMPTS) as $attempt) {
            loginThrottleFailure($this, "person{$attempt}@example.com", $address);
        }

        $user = User::factory()->create();

        loginThrottleAttempt($this, $user->email, $address);
        $this->assertGuest();

        // And it is the address that is held back, not the account: the same
        // credentials from somewhere else are still that person's own.
        loginThrottleAttempt($this, $user->email, '198.51.100.3');
        $this->assertAuthenticatedAs($user);
    });

    it('keeps counting an address through a successful sign-in', function () {
        /*
         * Somebody working through a stolen credential list will eventually
         * land on a password that works. Clearing the address budget there
         * would hand them a fresh thirty for the rest of the list — the one
         * moment the limit matters most is the moment this would switch it off.
         *
         * The identity is cleared, because the person who just proved they own
         * the account should not be a few typos from being locked out of it.
         */
        $user = User::factory()->create();
        $address = '203.0.113.77';

        foreach (range(1, LoginThrottle::IDENTITY_ATTEMPTS - 1) as $ignored) {
            loginThrottleFailure($this, $user->email, $address);
        }

        loginThrottleAttempt($this, $user->email, $address);
        $this->assertAuthenticatedAs($user);

        $probe = Request::create('/login', 'POST', ['email' => $user->email], server: [
            'REMOTE_ADDR' => $address,
        ]);

        $throttle = app(LoginThrottle::class);

        expect($throttle->attempts($probe))->toBe(0)
            ->and($throttle->attemptsFromAddress($probe))->toBe(LoginThrottle::IDENTITY_ATTEMPTS - 1);
    });
});

describe('what a refused attempt is told', function () {
    it('says the same thing whether or not the address has an account', function () {
        /*
         * §31.3: the sign-in form must not become a way of asking whether
         * somebody banks here. One message, and it names neither half of the
         * credentials as the wrong one.
         */
        $user = User::factory()->create();

        loginThrottleFailure($this, $user->email)
            ->assertSessionHasErrors(['email' => trans('auth.failed')]);

        loginThrottleFailure($this, 'nobody-here@example.com')
            ->assertSessionHasErrors(['email' => trans('auth.failed')]);
    });

    it('gives an automated client a 429 and a Retry-After it can act on', function () {
        // A message in a response body is not something a retry loop reads.
        $user = User::factory()->create();

        foreach (range(1, LoginThrottle::IDENTITY_ATTEMPTS) as $ignored) {
            loginThrottleFailure($this, $user->email);
        }

        $response = $this->postJson(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertTooManyRequests();

        expect((int) $response->headers->get('Retry-After'))
            ->toBeGreaterThan(0)
            ->toBeLessThanOrEqual(LoginThrottle::IDENTITY_DECAY_SECONDS);

        $this->assertGuest();
    });

    it('reaches the sign-in screen in the language the reader is using', function () {
        /*
         * The lockout lands in the same place every other sign-in failure does
         * — under the email field on the screen they are already looking at.
         * A 429 would replace it with an error page and lose what they typed.
         *
         * And it has to be readable: someone locked out by a sentence they
         * cannot parse has no way to tell "wrong password" from "wait a
         * moment", so they keep trying, which is what the wait exists to stop.
         */
        $user = User::factory()->create();

        foreach (range(1, LoginThrottle::IDENTITY_ATTEMPTS) as $ignored) {
            loginThrottleFailure($this, $user->email);
        }

        loginThrottleAttempt($this, $user->email)
            ->assertRedirect()
            ->assertSessionHasErrors('email');

        expect(session('errors')->first('email'))
            ->toContain('Too many login attempts');

        $this->withHeaders(['Accept-Language' => 'bn']);

        loginThrottleAttempt($this, $user->email)
            ->assertSessionHasErrors('email');

        expect(session('errors')->first('email'))
            ->toContain('সেকেন্ড পর আবার চেষ্টা করুন');
    });
});

describe('where the count is kept', function () {
    it('is Redis, so an extra web node does not multiply the allowance', function () {
        /*
         * Asserted against the config source, following the same reasoning as
         * the session cookie flags: `env()` is resolved once at boot, and the
         * suite deliberately counts in memory so a lockout cannot leak from one
         * test into the next. What matters here is structural anyway — that the
         * limiter names a store rather than following `cache.default`, because
         * five attempts per minute counted per node is five per minute *times
         * the number of nodes* (§40).
         */
        $source = file_get_contents(base_path('config/cache.php'));

        expect($source)->toContain("'limiter' => env('CACHE_LIMITER', 'redis')")
            ->and(config('cache.stores.redis.driver'))->toBe('redis')
            ->and(config('cache.stores.redis.connection'))->toBe('cache');
    });

    it('counts failures rather than requests', function () {
        /*
         * `fortify.limiters.login` names the `throttle` middleware, which counts
         * every request to the route. Login is one of the few endpoints where
         * that is the wrong unit: a successful sign-in would spend from the same
         * budget as a guess, so somebody signing in, out and in again would be
         * treated as an attacker. Null hands the count to the pipeline, which
         * only ever increments on a failed authentication.
         */
        expect(config('fortify.limiters.login'))->toBeNull();

        $user = User::factory()->create();

        foreach (range(1, LoginThrottle::IDENTITY_ATTEMPTS + 3) as $ignored) {
            loginThrottleAttempt($this, $user->email);
            $this->post(route('logout'));
        }

        loginThrottleAttempt($this, $user->email);
        $this->assertAuthenticatedAs($user);
    });

    it('normalizes the username the way authentication does', function () {
        /*
         * The throttle key is built before Fortify canonicalizes the username —
         * `EnsureLoginIsNotThrottled` runs first in the pipeline — so the two
         * have to agree independently. If lowercasing were ever switched off
         * here, authentication would look up the raw string while the limiter
         * kept folding case, and one account would have as many separate
         * budgets as its address has casings.
         */
        expect(LoginThrottle::normalize('  KARIM@Example.COM  '))->toBe('karim@example.com')
            ->and(config('fortify.lowercase_usernames'))->toBeTrue();
    });
});
