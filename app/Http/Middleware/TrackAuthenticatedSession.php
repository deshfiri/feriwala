<?php

namespace App\Http\Middleware;

use App\Domain\Account\Models\AuthenticatedSession;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Models\User;
use App\Notifications\Account\NewDeviceSignIn;
use App\Support\Security\DeviceSignature;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the record of where a person is signed in, and notices a new device (§6).
 *
 * Middleware rather than a listener on `Login`, for one concrete reason: Fortify
 * regenerates the session identifier *after* the login event fires, so a
 * listener would write down an identifier that no longer exists and every row
 * would be unmatchable. Running after the response is built means the identifier
 * recorded is the one the browser is about to be given.
 *
 * It also means the record covers sessions this application did not start — a
 * remembered login, a session already open when this shipped — instead of only
 * the ones that happened to pass through a login form.
 *
 * **The first session a person is ever seen with is never treated as new.**
 * There is nothing to compare it to, and without that rule the day this shipped
 * would have sent a "new device" alert to every existing user at once, which
 * teaches people that the alert means nothing.
 */
class TrackAuthenticatedSession
{
    /**
     * How often a live session's activity timestamp is rewritten.
     *
     * The screen shows "last active" to the minute, so writing on every request
     * would buy nothing and cost an update per page view.
     */
    public const ACTIVITY_INTERVAL_SECONDS = 60;

    public function __construct(
        protected DeviceSignature $devices,
        protected RecordAuditLog $audit,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        /*
         * Both sides of the request, because the two interesting cases sit on
         * opposite sides of it. An ordinary page view is already authenticated
         * on the way in, and recording it there means the security screen can
         * show the device it is being read on. A sign-in is not authenticated
         * until the controller has run, and its identifier is regenerated in
         * the same breath — so that one can only be recorded on the way out.
         */
        $before = $request->user();
        $sessionId = $request->hasSession() ? $request->session()->getId() : null;

        if ($before !== null && $sessionId !== null) {
            $this->record($request, $before, $sessionId);
        }

        $response = $next($request);

        $after = $request->user();

        if ($before === null && $after !== null && $request->hasSession()) {
            $this->record($request, $after, $request->session()->getId());
        }

        if ($before !== null && $after === null && $sessionId !== null) {
            $this->close($sessionId);
        }

        return $response;
    }

    /**
     * Mark the session finished, keeping the row.
     *
     * Signing out is the ordinary end of a session and the history should say
     * so — "you signed out of this device" and "this session expired" are
     * different answers to somebody checking what happened.
     */
    protected function close(string $sessionId): void
    {
        AuthenticatedSession::query()
            ->where('session_key', AuthenticatedSession::keyFor($sessionId))
            ->whereNull('ended_at')
            ->update([
                'ended_at' => now(),
                'ended_reason' => AuthenticatedSession::ENDED_SIGNED_OUT,
                'session_id' => null,
                'updated_at' => now(),
            ]);
    }

    protected function record(Request $request, ?object $user, string $sessionId): void
    {
        if (! $user instanceof User) {
            return;
        }

        $key = AuthenticatedSession::keyFor($sessionId);

        $existing = AuthenticatedSession::query()->where('session_key', $key)->first();

        if ($existing !== null) {
            $this->touch($existing);

            return;
        }

        $this->open($request, $user, $sessionId, $key);
    }

    protected function touch(AuthenticatedSession $session): void
    {
        if ($session->last_active_at->addSeconds(self::ACTIVITY_INTERVAL_SECONDS)->isFuture()) {
            return;
        }

        $session->forceFill(['last_active_at' => now()])->save();
    }

    protected function open(Request $request, User $user, string $sessionId, string $key): void
    {
        $agent = $request->userAgent();
        $fingerprint = $this->devices->fingerprint($agent);

        /*
         * Both questions are asked before the row is written, because writing it
         * first would make every device known and every person previously seen.
         */
        $seenBefore = AuthenticatedSession::query()
            ->where('user_id', $user->id)
            ->exists();

        $knownDevice = AuthenticatedSession::query()
            ->where('user_id', $user->id)
            ->where('device_fingerprint', $fingerprint)
            ->exists();

        $device = $this->devices->label($agent);

        $session = AuthenticatedSession::create([
            'user_id' => $user->id,
            'session_key' => $key,
            'session_id' => $sessionId,
            'device_fingerprint' => $fingerprint,
            'device_label' => $device,
            'ip_address' => $request->ip(),
            'user_agent' => $agent === null ? null : mb_substr($agent, 0, 512),
            'last_active_at' => now(),
        ]);

        if (! $seenBefore || $knownDevice) {
            return;
        }

        $this->raise($user, $session);
    }

    /**
     * Tell the person, and write it down.
     *
     * A new address on a device already known is deliberately silent: people
     * travel, and mobile networks reassign addresses constantly, so alerting on
     * that trains the reader to ignore the alert that matters. The address is
     * still recorded and still shown in the session list — it is the new
     * *device* that is worth interrupting somebody for.
     */
    protected function raise(User $user, AuthenticatedSession $session): void
    {
        $this->audit->handle(new AuditEntry(
            action: 'identity.new_device_sign_in',

            // The credentials were used by whoever holds them, so the identity
            // is the actor. Whether it was the person is exactly the question
            // the notification asks.
            actorId: $user->id,
            auditableType: User::class,
            auditableId: $user->id,
            after: [
                'device' => $session->device_label,
                'ip_address' => $session->ip_address,
            ],
            ipAddress: $session->ip_address,
            userAgent: $session->user_agent,
            module: 'identity',
            isSensitive: true,
        ));

        $user->notify(new NewDeviceSignIn(
            device: $session->device_label,
            ipAddress: $session->ip_address,
            signedInAt: $session->last_active_at->toDayDateTimeString(),
        ));
    }
}
