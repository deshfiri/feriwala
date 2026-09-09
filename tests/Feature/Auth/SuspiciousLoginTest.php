<?php

use App\Domain\Account\Models\AuthenticatedSession;
use App\Domain\Audit\Models\AuditLog;
use App\Models\User;
use App\Notifications\Account\NewDeviceSignIn;
use App\Support\Security\DeviceSignature;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/*
 * Noticing a sign-in the account holder did not make (P1-15, §6).
 *
 * A stolen password is invisible to the person it was stolen from. This is
 * usually the only thing that ever tells them.
 */

const SUSPICIOUS_LOGIN_CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';
const SUSPICIOUS_LOGIN_SAFARI = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15';

/**
 * One authenticated page view, from a stated device and address.
 */
function suspiciousLoginVisit(
    TestCase $test,
    User $user,
    string $userAgent = SUSPICIOUS_LOGIN_CHROME,
    string $address = '203.0.113.5',
): void {
    $test->withServerVariables(['REMOTE_ADDR' => $address])
        ->withHeaders(['User-Agent' => $userAgent])
        ->actingAs($user)
        ->get(route('dashboard'));
}

/**
 * Carry the current session identifier into the next request.
 *
 * A browser does this on its own. The test client sends no cookies back, so
 * every request would otherwise start a session of its own — which is the right
 * default for "a different device signs in" and wrong for "the same one comes
 * back", so the second case says so explicitly.
 */
function suspiciousLoginKeepSession(TestCase $test): void
{
    $test->withCookie(
        (string) config('session.cookie'),
        app('session.store')->getId(),
    );
}

beforeEach(function () {
    $this->owner = User::factory()
        ->withBusinessAccount(fn ($account) => $account->active())
        ->create();
});

describe('the record of where somebody is signed in', function () {
    it('writes down the device and the address', function () {
        suspiciousLoginVisit($this, $this->owner);

        $session = AuthenticatedSession::query()->firstOrFail();

        expect($session->user_id)->toBe($this->owner->id)
            ->and($session->device_label)->toBe('Chrome on Windows')
            ->and($session->ip_address)->toBe('203.0.113.5')
            ->and($session->ended_at)->toBeNull();
    });

    it('keeps one row per session rather than one per page view', function () {
        suspiciousLoginVisit($this, $this->owner);

        suspiciousLoginKeepSession($this);
        suspiciousLoginVisit($this, $this->owner);
        suspiciousLoginVisit($this, $this->owner);

        expect(AuthenticatedSession::query()->count())->toBe(1);
    });

    it('never stores the session identifier in the clear', function () {
        /*
         * Ending a session needs the identifier, so it is kept — but a database
         * dump must not be a set of live sessions. The column is encrypted and
         * the row is found by a hash, so nothing has to read the value back in
         * order to match a request to its session.
         */
        suspiciousLoginVisit($this, $this->owner);

        $session = AuthenticatedSession::query()->firstOrFail();

        // Straight off the connection, so the cast does not quietly decrypt it
        // and turn this into an assertion that nothing is true.
        $stored = (string) DB::table('authenticated_sessions')
            ->where('id', $session->id)
            ->value('session_id');

        expect($stored)->not->toBe($session->session_id)
            ->and($session->session_key)->toBe(
                AuthenticatedSession::keyFor((string) $session->session_id),
            );
    });

    it('records nothing for a visitor who is not signed in', function () {
        $this->get(route('login'))->assertOk();

        expect(AuthenticatedSession::query()->count())->toBe(0);
    });
});

describe('a device that has not been seen before', function () {
    it('is not treated as new the first time somebody is ever seen', function () {
        /*
         * There is nothing to compare a first sign-in to. Without this rule the
         * day this shipped would have alerted every existing user at once, which
         * is how people learn that the alert means nothing.
         */
        Notification::fake();

        suspiciousLoginVisit($this, $this->owner);

        Notification::assertNothingSent();
    });

    it('warns the account holder, by mail as well as in the ERP', function () {
        Notification::fake();

        suspiciousLoginVisit($this, $this->owner, SUSPICIOUS_LOGIN_CHROME);

        suspiciousLoginVisit($this, $this->owner, SUSPICIOUS_LOGIN_SAFARI, '198.51.100.9');

        Notification::assertSentTo(
            $this->owner,
            NewDeviceSignIn::class,
            function (NewDeviceSignIn $notification) {
                // Mail matters more than the bell here: somebody already signed
                // in as the victim can read the bell and cannot read the inbox.
                expect($notification->via($this->owner))->toContain('mail')
                    ->and($notification->device)->toBe('Safari on macOS')
                    ->and($notification->ipAddress)->toBe('198.51.100.9');

                return true;
            },
        );
    });

    it('is written to the audit trail as a sensitive event', function () {
        suspiciousLoginVisit($this, $this->owner, SUSPICIOUS_LOGIN_CHROME);

        suspiciousLoginVisit($this, $this->owner, SUSPICIOUS_LOGIN_SAFARI, '198.51.100.9');

        $entry = AuditLog::query()
            ->where('action', 'identity.new_device_sign_in')
            ->firstOrFail();

        expect($entry->auditable_id)->toBe($this->owner->id)
            ->and($entry->is_sensitive)->toBeTrue()
            ->and($entry->ip_address)->toBe('198.51.100.9');
    });

    it('stays quiet when a known device returns from somewhere else', function () {
        /*
         * People travel, and a mobile network reassigns addresses constantly.
         * Alerting on a new address trains the reader to ignore the alert that
         * matters. The address is still recorded and still shown in the list.
         */
        Notification::fake();

        suspiciousLoginVisit($this, $this->owner, SUSPICIOUS_LOGIN_CHROME, '203.0.113.5');

        suspiciousLoginVisit($this, $this->owner, SUSPICIOUS_LOGIN_CHROME, '103.4.5.6');

        Notification::assertNothingSent();

        expect(AuthenticatedSession::query()->count())->toBe(2);
    });

    it('is judged per person, not across the whole platform', function () {
        // Another account signing in from an identical browser says nothing
        // about whether this person has used one.
        Notification::fake();

        $stranger = User::factory()
            ->withBusinessAccount(fn ($account) => $account->active())
            ->create();

        suspiciousLoginVisit($this, $stranger, SUSPICIOUS_LOGIN_SAFARI);

        suspiciousLoginVisit($this, $this->owner, SUSPICIOUS_LOGIN_SAFARI);

        // The owner's first ever session — no alert, and not "known" because
        // somebody else used the same browser.
        Notification::assertNothingSent();
    });
});

describe('naming the device', function () {
    it('says what a person would recognise, and admits when it cannot', function () {
        $devices = new DeviceSignature;

        expect($devices->label(SUSPICIOUS_LOGIN_CHROME))->toBe('Chrome on Windows')
            ->and($devices->label(SUSPICIOUS_LOGIN_SAFARI))->toBe('Safari on macOS')
            ->and($devices->label('curl/8.5.0'))->toBe('Unknown device')
            ->and($devices->label(null))->toBe('Unknown device');
    });

    it('tells two browsers apart even when they are described the same way', function () {
        /*
         * Edge says "Chrome" and "Safari" in its own user agent. The label may
         * collapse them; the fingerprint must not, or signing in from a
         * different browser on the same machine would pass unnoticed.
         */
        $devices = new DeviceSignature;

        expect($devices->fingerprint(SUSPICIOUS_LOGIN_CHROME))
            ->not->toBe($devices->fingerprint(SUSPICIOUS_LOGIN_SAFARI));
    });
});
