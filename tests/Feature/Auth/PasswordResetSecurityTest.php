<?php

use App\Domain\Account\Models\AuthenticatedSession;
use App\Domain\Audit\Models\AuditLog;
use App\Models\User;
use App\Notifications\Account\PasswordChanged;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/*
 * Password reset security (P1-19, §6).
 *
 * A reset is usually somebody recovering from having lost control of the
 * account. Changing the password does nothing at all to a session that is
 * already open, which makes a reset feel like a fix while changing nothing
 * about the actual problem.
 */

/**
 * Request a reset link and return the token it carried.
 */
function passwordResetToken(TestCase $test, User $user): string
{
    $token = '';

    Notification::fake();

    $test->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token) {
        $token = $notification->token;

        return true;
    });

    return $token;
}

beforeEach(function () {
    $this->user = User::factory()
        ->withBusinessAccount(fn ($account) => $account->active())
        ->create();
});

describe('the reset token', function () {
    it('works once and never again', function () {
        $token = passwordResetToken($this, $this->user);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $this->user->email,
            'password' => testStrongPassword(),
            'password_confirmation' => testStrongPassword(),
        ])->assertSessionHasNoErrors();

        // The row is gone, so the same link in a forwarded email is worth
        // nothing to whoever reads it next.
        expect(DB::table('password_reset_tokens')->where('email', $this->user->email)->exists())
            ->toBeFalse();

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $this->user->email,
            'password' => 'Another!Password#2026',
            'password_confirmation' => 'Another!Password#2026',
        ])->assertSessionHasErrors('email');

        // And the second attempt changed nothing.
        expect(Hash::check(testStrongPassword(), $this->user->refresh()->password))->toBeTrue();
    });

    it('stops working once it has expired', function () {
        /*
         * A reset link is a password sitting in a mailbox, and the mailbox is
         * the thing most likely to be compromised later. The window bounds what
         * a link found in archived mail is worth.
         */
        $token = passwordResetToken($this, $this->user);
        $before = $this->user->password;

        $this->travel((int) config('auth.passwords.users.expire') + 1)->minutes();

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $this->user->email,
            'password' => testStrongPassword(),
            'password_confirmation' => testStrongPassword(),
        ])->assertSessionHasErrors('email');

        expect($this->user->refresh()->password)->toBe($before);
    });

    it('is bounded rather than left open', function () {
        expect((int) config('auth.passwords.users.expire'))->toBeGreaterThan(0)
            ->and((int) config('auth.passwords.users.expire'))->toBeLessThanOrEqual(120)
            // And a second link cannot be requested straight away, so the form
            // cannot be used to flood a mailbox with live credentials.
            ->and((int) config('auth.passwords.users.throttle'))->toBeGreaterThan(0);
    });
});

describe('after a reset', function () {
    it('signs out everywhere, including the session the attacker has', function () {
        /*
         * The whole point. Somebody resetting their password is not signed in,
         * so there is no "current" session to keep — and the session that
         * matters is the one they are trying to get rid of.
         */
        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/131.0'])
            ->actingAs($this->user)
            ->get(route('dashboard'));

        expect($this->user->authenticatedSessions()->live()->count())->toBe(1);

        auth()->logout();

        $token = passwordResetToken($this, $this->user);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $this->user->email,
            'password' => testStrongPassword(),
            'password_confirmation' => testStrongPassword(),
        ]);

        $session = $this->user->authenticatedSessions()->firstOrFail();

        expect($session->ended_at)->not->toBeNull()
            ->and($session->ended_reason)->toBe(AuthenticatedSession::ENDED_PASSWORD_CHANGED);
    });

    it('tells the account holder, by mail as well as in the ERP', function () {
        // Somebody who reads this and did not reset their password has just
        // learned that whoever did has their mailbox.
        $token = passwordResetToken($this, $this->user);

        Notification::fake();

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $this->user->email,
            'password' => testStrongPassword(),
            'password_confirmation' => testStrongPassword(),
        ]);

        Notification::assertSentTo(
            $this->user,
            PasswordChanged::class,
            function (PasswordChanged $notification) {
                expect($notification->via($this->user))->toContain('mail')
                    ->and($notification->viaReset)->toBeTrue();

                return true;
            },
        );
    });

    it('records the event without recording the password', function () {
        $token = passwordResetToken($this, $this->user);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $this->user->email,
            'password' => testStrongPassword(),
            'password_confirmation' => testStrongPassword(),
        ]);

        $entry = AuditLog::query()->where('action', 'identity.password_reset')->firstOrFail();

        expect($entry->auditable_id)->toBe($this->user->id)
            // No human decided this: a reset link is opened by whoever holds
            // the mailbox, and naming them as an actor would be an invention.
            ->and($entry->actor_type)->toBe('system')
            ->and($entry->actor_id)->toBeNull()
            ->and(json_encode($entry->after))->not->toContain(testStrongPassword());
    });
});

describe('changing the password from the security screen', function () {
    it('signs out the other devices and keeps this one', function () {
        // Changing a password is what somebody does when they think another
        // device is not theirs. This one survives because they are sitting at it.
        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (Macintosh) Firefox/130.0'])
            ->actingAs($this->user)
            ->get(route('dashboard'));

        $elsewhere = AuthenticatedSession::query()->latest('id')->firstOrFail();

        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/131.0'])
            ->actingAs($this->user)
            ->get(route('dashboard'));

        $this->withCookie((string) config('session.cookie'), app('session.store')->getId());
        $here = AuthenticatedSession::query()->latest('id')->firstOrFail();

        $this->withSession(['auth.password_confirmed_at' => time()])
            ->put(route('user-password.update'), [
                'current_password' => 'password',
                'password' => testStrongPassword(),
                'password_confirmation' => testStrongPassword(),
            ])
            ->assertSessionHasNoErrors();

        expect($elsewhere->refresh()->ended_at)->not->toBeNull()
            ->and($elsewhere->ended_reason)->toBe(AuthenticatedSession::ENDED_PASSWORD_CHANGED)
            ->and($here->refresh()->ended_at)->toBeNull();

        $this->assertAuthenticatedAs($this->user);
    });

    it('tells the account holder', function () {
        Notification::fake();

        $this->actingAs($this->user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->put(route('user-password.update'), [
                'current_password' => 'password',
                'password' => testStrongPassword(),
                'password_confirmation' => testStrongPassword(),
            ])
            ->assertSessionHasNoErrors();

        Notification::assertSentTo(
            $this->user,
            PasswordChanged::class,
            fn (PasswordChanged $notification) => $notification->viaReset === false,
        );
    });
});

describe('what the reset form gives away', function () {
    it('answers an unknown address exactly as it answers a known one', function () {
        /*
         * §31.3: the reset form must not become a way of asking whether
         * somebody banks here. Fortify's own response reports the broker's
         * status on the email field — "we can't find a user with that email
         * address" for one and "check your inbox" for another — which is an
         * enumeration oracle needing no credentials and no rate limit to work
         * through a list. The same reasoning P1-14 applied to the sign-in form.
         */
        Notification::fake();

        $this->post(route('password.email'), ['email' => $this->user->email])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', trans(Password::RESET_LINK_SENT));

        $this->post(route('password.email'), ['email' => 'nobody-here@example.com'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', trans(Password::RESET_LINK_SENT));

        // Said, but not done: no link was sent to an address that has no account.
        Notification::assertSentToTimes($this->user, ResetPassword::class, 1);
    });

    it('says the same thing when a second link is asked for too soon', function () {
        // Only a real address can be throttled, so reporting the throttle
        // identifies one just as clearly as reporting the absence.
        Notification::fake();

        $this->post(route('password.email'), ['email' => $this->user->email]);

        $this->post(route('password.email'), ['email' => $this->user->email])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', trans(Password::RESET_LINK_SENT));

        Notification::assertSentToTimes($this->user, ResetPassword::class, 1);
    });
});
