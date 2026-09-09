<?php

namespace App\Domain\Account\Actions;

use App\Domain\Account\Models\AuthenticatedSession;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Models\User;
use Illuminate\Session\SessionManager;

/**
 * Signs a person out of somewhere they are not (§6).
 *
 * The session record is not the session. Marking a row ended and leaving the
 * Redis key alone would produce a screen that says a device has been signed out
 * while that device carries on working — the worst possible outcome for a
 * control somebody reaches for when they think they have been compromised. So
 * the handler destroys the session first, and the row is bookkeeping.
 *
 * Ending the current session is deliberately not offered here. That is what
 * signing out is, and doing it through this path would leave the person on a
 * page that no longer has them.
 */
class EndAuthenticatedSessions
{
    public function __construct(
        protected SessionManager $sessions,
        protected RecordAuditLog $audit,
    ) {}

    /**
     * End every live session except the one asking.
     *
     * @return int how many were ended
     */
    public function exceptCurrent(
        User $user,
        string $currentSessionId,
        string $reason = AuthenticatedSession::ENDED_REVOKED,
    ): int {
        $sessions = $user->authenticatedSessions()
            ->live()
            ->where('session_key', '!=', AuthenticatedSession::keyFor($currentSessionId))
            ->get();

        foreach ($sessions as $session) {
            $this->end($session, $reason);
        }

        $ended = $sessions->count();

        if ($ended > 0) {
            $this->record($user, $ended, $reason);
        }

        return $ended;
    }

    /**
     * End every live session this person has.
     *
     * Used when a login is locked (§6): a lock that leaves an open tab working
     * until the person happens to reload is not a lock.
     *
     * @return int how many were ended
     */
    public function all(User $user, string $reason = AuthenticatedSession::ENDED_REVOKED): int
    {
        $sessions = $user->authenticatedSessions()->live()->get();

        foreach ($sessions as $session) {
            $this->end($session, $reason);
        }

        $ended = $sessions->count();

        if ($ended > 0) {
            $this->record($user, $ended, $reason);
        }

        return $ended;
    }

    /**
     * End one session.
     */
    public function one(
        User $user,
        AuthenticatedSession $session,
        string $reason = AuthenticatedSession::ENDED_REVOKED,
    ): void {
        if ($session->hasEnded()) {
            return;
        }

        $this->end($session, $reason);
        $this->record($user, 1, $reason);
    }

    protected function end(AuthenticatedSession $session, string $reason): void
    {
        $sessionId = $session->session_id;

        if ($sessionId !== null) {
            /*
             * Destroying a key that is already gone is not an error, and the
             * common case is exactly that: the session expired on its own and
             * nothing had noticed yet.
             */
            $this->sessions->driver()->getHandler()->destroy($sessionId);
        }

        $session->forceFill([
            'ended_at' => now(),
            'ended_reason' => $reason,

            // The identifier has no further use, and a dead session's key is
            // still a key. Dropping it keeps the history without keeping the
            // thing that could be replayed if the encryption ever failed.
            'session_id' => null,
        ])->save();
    }

    protected function record(User $user, int $count, string $reason): void
    {
        $this->audit->handle(new AuditEntry(
            action: 'identity.sessions_ended',
            actorId: $user->id,
            auditableType: User::class,
            auditableId: $user->id,
            after: ['sessions_ended' => $count, 'reason' => $reason],
            module: 'identity',
            isSensitive: true,
        ));
    }
}
