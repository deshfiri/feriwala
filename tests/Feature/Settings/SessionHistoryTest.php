<?php

use App\Domain\Account\Models\AuthenticatedSession;
use App\Domain\Audit\Models\AuditLog;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/*
 * Device and session history, and signing out of somewhere else (P1-16, §6).
 *
 * The list is the security control, not decoration: somebody who thinks their
 * password has been taken needs to end the sessions they did not start without
 * waiting for support.
 */

/**
 * Sign in from a device, leaving a session behind.
 */
function sessionHistoryVisit(TestCase $test, User $user, string $device): AuthenticatedSession
{
    $test->withHeaders(['User-Agent' => $device])
        ->actingAs($user)
        ->get(route('dashboard'));

    return AuthenticatedSession::query()->latest('id')->firstOrFail();
}

/**
 * Stay on the current session for the rest of the test, the way a browser does.
 */
function sessionHistoryStay(TestCase $test): void
{
    $test->withCookie(
        (string) config('session.cookie'),
        app('session.store')->getId(),
    );

    $test->withSession(['auth.password_confirmed_at' => time()]);
}

beforeEach(function () {
    $this->user = User::factory()
        ->withBusinessAccount(fn ($account) => $account->active())
        ->create();
});

describe('the list of sessions', function () {
    it('names the device being read on and the ones that are not', function () {
        sessionHistoryVisit($this, $this->user, 'Mozilla/5.0 (Windows NT 10.0) Chrome/131.0');
        sessionHistoryVisit($this, $this->user, 'Mozilla/5.0 (Macintosh) Firefox/130.0');
        sessionHistoryStay($this);

        $this->get(route('security.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/security')
                ->has('sessions', 2)
                ->where('sessions.0.is_current', true)
                ->where('sessions.0.device', 'Firefox on macOS')
                ->where('sessions.1.is_current', false)
                ->where('sessions.1.device', 'Chrome on Windows'),
            );
    });

    it('never sends the session identifier to the browser', function () {
        /*
         * The identifier is what lets a session be assumed. The screen has no
         * use for it — the device and the address are what identify a row to
         * the person reading — so it is not among the props at all.
         */
        sessionHistoryVisit($this, $this->user, 'Mozilla/5.0 (Windows NT 10.0) Chrome/131.0');
        sessionHistoryStay($this);

        $session = AuthenticatedSession::query()->firstOrFail();

        $this->get(route('security.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/security')
                ->missing('sessions.0.session_id')
                ->missing('sessions.0.session_key'),
            )
            ->assertDontSee((string) $session->session_id);
    });
});

describe('ending one other session', function () {
    it('destroys the session rather than only marking the row', function () {
        /*
         * A screen that says a device has been signed out while that device
         * carries on working is the worst possible outcome for a control
         * somebody reaches for when they think they are compromised.
         */
        $other = sessionHistoryVisit($this, $this->user, 'Mozilla/5.0 (Macintosh) Firefox/130.0');
        $identifier = (string) $other->session_id;

        sessionHistoryVisit($this, $this->user, 'Mozilla/5.0 (Windows NT 10.0) Chrome/131.0');
        sessionHistoryStay($this);

        $this->delete(route('security.sessions.destroy', $other->public_id))
            ->assertRedirect();

        $other->refresh();

        expect($other->ended_at)->not->toBeNull()
            ->and($other->ended_reason)->toBe(AuthenticatedSession::ENDED_REVOKED)
            // The identifier has no further use, and a dead session's key is
            // still a key.
            ->and($other->session_id)->toBeNull()
            ->and(app('session')->driver()->getHandler()->read($identifier))->toBe('');
    });

    it('cannot reach a session belonging to somebody else', function () {
        // §31.3: the row is found through the signed-in person's own relation,
        // so there is nothing for a changed parameter to reach.
        $stranger = User::factory()->create();
        $theirs = sessionHistoryVisit($this, $stranger, 'Mozilla/5.0 (Macintosh) Firefox/130.0');

        sessionHistoryVisit($this, $this->user, 'Mozilla/5.0 (Windows NT 10.0) Chrome/131.0');
        sessionHistoryStay($this);

        $this->delete(route('security.sessions.destroy', $theirs->public_id))
            ->assertNotFound();

        expect($theirs->refresh()->ended_at)->toBeNull();
    });

    it('refuses to end the session doing the asking', function () {
        // That is what signing out is, and doing it here would leave the person
        // on a page that no longer has them.
        $current = sessionHistoryVisit($this, $this->user, 'Mozilla/5.0 (Windows NT 10.0) Chrome/131.0');
        sessionHistoryStay($this);

        $this->delete(route('security.sessions.destroy', $current->public_id))
            ->assertSessionHasErrors('session');

        expect($current->refresh()->ended_at)->toBeNull();
    });
});

describe('signing out of every other device', function () {
    it('ends them all and leaves this one alone', function () {
        sessionHistoryVisit($this, $this->user, 'Mozilla/5.0 (Macintosh) Firefox/130.0');
        sessionHistoryVisit($this, $this->user, 'Mozilla/5.0 (Linux; Android 14) Chrome/131.0');
        $current = sessionHistoryVisit($this, $this->user, 'Mozilla/5.0 (Windows NT 10.0) Chrome/131.0');
        sessionHistoryStay($this);

        $this->delete(route('security.sessions.purge'))->assertRedirect();

        expect(AuthenticatedSession::query()->whereNull('ended_at')->count())->toBe(1)
            ->and($current->refresh()->ended_at)->toBeNull();
    });

    it('leaves sessions belonging to other people alone', function () {
        $stranger = User::factory()->create();
        $theirs = sessionHistoryVisit($this, $stranger, 'Mozilla/5.0 (Macintosh) Firefox/130.0');

        sessionHistoryVisit($this, $this->user, 'Mozilla/5.0 (Macintosh) Firefox/130.0');
        sessionHistoryVisit($this, $this->user, 'Mozilla/5.0 (Windows NT 10.0) Chrome/131.0');
        sessionHistoryStay($this);

        $this->delete(route('security.sessions.purge'))->assertRedirect();

        expect($theirs->refresh()->ended_at)->toBeNull();
    });

    it('is written to the audit trail', function () {
        sessionHistoryVisit($this, $this->user, 'Mozilla/5.0 (Macintosh) Firefox/130.0');
        sessionHistoryVisit($this, $this->user, 'Mozilla/5.0 (Windows NT 10.0) Chrome/131.0');
        sessionHistoryStay($this);

        $this->delete(route('security.sessions.purge'));

        $entry = AuditLog::query()
            ->where('action', 'identity.sessions_ended')
            ->firstOrFail();

        expect($entry->actor_id)->toBe($this->user->id)
            ->and($entry->is_sensitive)->toBeTrue();
    });
});

describe('who may do it', function () {
    it('asks for the password again first', function () {
        /*
         * Somebody who walks up to an unattended laptop must not be able to
         * sign the owner out of everywhere else and keep the one session they
         * are sitting at.
         */
        $other = sessionHistoryVisit($this, $this->user, 'Mozilla/5.0 (Macintosh) Firefox/130.0');

        sessionHistoryVisit($this, $this->user, 'Mozilla/5.0 (Windows NT 10.0) Chrome/131.0');

        // Deliberately without the password confirmation `sessionHistoryStay`
        // grants.
        $this->withCookie((string) config('session.cookie'), app('session.store')->getId());

        $this->delete(route('security.sessions.purge'))
            ->assertRedirect(route('password.confirm'));

        expect($other->refresh()->ended_at)->toBeNull();
    });

    it('is closed to a visitor who is not signed in', function () {
        $this->delete(route('security.sessions.purge'))
            ->assertRedirect(route('login'));
    });
});
