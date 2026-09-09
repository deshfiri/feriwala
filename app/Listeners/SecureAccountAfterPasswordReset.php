<?php

namespace App\Listeners;

use App\Domain\Account\Actions\EndAuthenticatedSessions;
use App\Domain\Account\Models\AuthenticatedSession;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Models\User;
use App\Notifications\Account\PasswordChanged;
use Illuminate\Auth\Events\PasswordReset;

/**
 * Finishes the job a password reset starts (§6).
 *
 * A reset is usually somebody recovering from having lost control of the
 * account — and changing the password does nothing at all to a session that is
 * already open. Whoever was signed in stays signed in, which makes the reset
 * feel like a fix while changing nothing about the actual problem. So every
 * session ends, including the one the person doing the reset does not have,
 * because they are not signed in yet.
 *
 * And they are told. Somebody who reads "your password was changed" and did not
 * change it has just learned that whoever did has their mailbox, which is the
 * only warning this situation ever gives.
 *
 * The audit entry records the fact, never the password (§42).
 */
class SecureAccountAfterPasswordReset
{
    public function __construct(
        protected EndAuthenticatedSessions $endSessions,
        protected RecordAuditLog $audit,
    ) {}

    public function handle(PasswordReset $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        $this->endSessions->all($user, AuthenticatedSession::ENDED_PASSWORD_CHANGED);

        $user->notify(new PasswordChanged(viaReset: true));

        $this->audit->handle(new AuditEntry(
            action: 'identity.password_reset',

            // Nobody exercised authority here: a reset link is opened by
            // whoever holds the mailbox, and whether that is the account holder
            // is precisely the question the notification asks.
            actorType: 'system',
            auditableType: User::class,
            auditableId: $user->id,
            module: 'identity',
            isSensitive: true,
        ));
    }
}
