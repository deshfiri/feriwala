<?php

use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Models\User;
use App\Support\Security\SessionPolicy;

/*
 * Session lifetime, storage and cookie flags (P1-13, §6, §36).
 *
 * Three separate questions that all end up in the same cookie: how long a
 * session lasts, where it is kept, and what a browser is allowed to do with the
 * identifier.
 */

describe('how long a session lasts', function () {
    it('falls back to the deployed configuration when nothing is set', function () {
        config(['session.lifetime' => 120]);

        expect(app(SessionPolicy::class)->lifetimeInMinutes())->toBe(120);
    });

    it('can be changed at runtime without a deploy', function () {
        // §36: "log people out sooner" is an operational decision.
        $settings = app(SettingsRepository::class);
        $settings->define(SessionPolicy::LIFETIME, 'security', SettingType::Integer);
        $settings->set(SessionPolicy::LIFETIME, 30);

        expect(app(SessionPolicy::class)->lifetimeInMinutes())->toBe(30);
    });

    it('reaches the session config before the session starts', function () {
        /*
         * The middleware is prepended for this reason: `StartSession` reads the
         * lifetime when it builds the store and again when it writes the
         * cookie, so a value applied afterwards changes nothing this request
         * while looking as though it worked.
         */
        $settings = app(SettingsRepository::class);
        $settings->define(SessionPolicy::LIFETIME, 'security', SettingType::Integer);
        $settings->set(SessionPolicy::LIFETIME, 45);

        $this->get(route('login'))->assertOk();

        expect(config('session.lifetime'))->toBe(45);
    });

    it('refuses a value that would lock everyone out or never expire', function () {
        // A mistyped setting is the likely case, not a malicious one.
        $settings = app(SettingsRepository::class);
        $settings->define(SessionPolicy::LIFETIME, 'security', SettingType::Integer);

        $settings->set(SessionPolicy::LIFETIME, 0);
        expect(app(SessionPolicy::class)->lifetimeInMinutes())
            ->toBe(SessionPolicy::MINIMUM_MINUTES);

        app()->forgetInstance(SessionPolicy::class);
        app(SettingsRepository::class)->flush();

        $settings->set(SessionPolicy::LIFETIME, 999999);
        expect(app(SessionPolicy::class)->lifetimeInMinutes())
            ->toBe(SessionPolicy::MAXIMUM_MINUTES);
    });
});

describe('where sessions are kept', function () {
    it('uses a Redis database of its own', function () {
        /*
         * `cache:clear` flushes a whole Redis database. Sessions sharing one
         * with the cache would make a routine deploy step sign out every user,
         * which reads as an outage rather than as housekeeping.
         */
        expect(config('session.connection'))->toBe('session');

        $session = config('database.redis.session.database');
        $cache = config('database.redis.cache.database');
        $default = config('database.redis.default.database');
        $locks = config('database.redis.locks.database');

        expect($session)->not->toBe($cache)
            ->and($session)->not->toBe($default)
            ->and($session)->not->toBe($locks);
    });

    it('is configured to a driver that survives more than one web node', function () {
        // §40, D10: the app stays stateless and horizontally scalable, so the
        // file and database drivers are both wrong here.
        expect(config('session.driver'))->toBeIn(['redis', 'array']);
    });
});

describe('the cookie a browser is given', function () {
    it('is closed to JavaScript', function () {
        expect(config('session.http_only'))->toBeTrue();
    });

    it('does not travel on a cross-site request', function () {
        // Lax still allows a top-level navigation, which is what keeps an
        // emailed link working while a cross-site POST carries nothing.
        expect(config('session.same_site'))->toBe('lax');
    });

    it('derives Secure from the environment rather than defaulting to null', function () {
        /*
         * Asserted against the config source, because `env()` is resolved once
         * at boot and cannot be re-read here with a different APP_ENV — and the
         * property that matters is structural anyway.
         *
         * The framework default is a bare `env('SESSION_SECURE_COOKIE')`, which
         * is null when unset and so never marks the cookie Secure. A production
         * deployment that forgot the variable would ship a session identifier a
         * downgrade attack can read, and nothing would say so.
         */
        $source = file_get_contents(base_path('config/session.php'));

        expect($source)->toContain("env('SESSION_SECURE_COOKIE', env('APP_ENV') === 'production')");
    });

    it('stays unmarked locally so plain HTTP still signs in', function () {
        /*
         * `php artisan serve` is HTTP. A Secure cookie would never come back,
         * and login would appear to do nothing at all — the failure mode this
         * environment split exists to avoid (D5).
         */
        expect(config('session.secure'))->toBeFalsy();

        $user = User::factory()->create();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
    });
});

describe('the identifier itself', function () {
    it('is replaced when somebody signs in', function () {
        // Session fixation: an identifier issued before authentication must not
        // still be valid after it.
        $user = User::factory()->create();

        $this->get(route('login'));
        $before = session()->getId();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        expect(session()->getId())->not->toBe($before);
        $this->assertAuthenticatedAs($user);
    });

    it('is thrown away with the CSRF token when somebody signs out', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('login'));

        $before = session()->getId();
        $token = session()->token();

        $this->actingAs($user)->post(route('logout'));

        $this->assertGuest();

        expect(session()->getId())->not->toBe($before)
            ->and(session()->token())->not->toBe($token);
    });
});

describe('when a session has expired', function () {
    it('returns to login rather than looping', function () {
        $user = User::factory()
            ->withBusinessAccount(fn ($account) => $account->active())
            ->create();

        // Signed in, then the session is gone — the state a returning browser
        // presents when the record has expired out of Redis.
        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        auth()->logout();
        session()->flush();

        $this->get(route('dashboard'))->assertRedirect(route('login'));

        // And the login page itself opens, rather than bouncing again.
        $this->get(route('login'))->assertOk();
    });

    it('remembers where the person was trying to go', function () {
        // Laravel stores the intended URL, so signing in again lands them where
        // they meant to be rather than on a generic home page.
        $user = User::factory()
            ->withBusinessAccount(fn ($account) => $account->active())
            ->create();

        $this->get(route('subscription.show'))->assertRedirect(route('login'));

        expect(session()->get('url.intended'))->toBe(route('subscription.show'));
    });
});
