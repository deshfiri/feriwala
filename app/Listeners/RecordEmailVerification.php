<?php

namespace App\Listeners;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Audit\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Events\Verified;

/**
 * Writes down that an address was confirmed (§6, §42).
 *
 * The actor is the **system**, not the person. Somebody clicking a link in
 * their own mailbox is not an administrator acting on an account, and recording
 * them as one would put a human name against an event nobody decided — which is
 * the difference between an audit trail and a log.
 *
 * Idempotent by construction: `Verified` fires from `markEmailAsVerified()`,
 * and Fortify returns early on an already-verified user, so a link opened twice
 * writes one entry. The test pins that rather than trusting it.
 */
class RecordEmailVerification
{
    public function __construct(
        protected RecordAuditLog $audit,
    ) {}

    public function handle(Verified $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        /*
         * One entry per identity, however many times the event arrives.
         *
         * Fortify already returns early on an address it has confirmed, so a
         * link opened twice fires this once — but that guarantee lives in
         * somebody else's controller, and an audit trail that quietly doubles
         * is not something anybody reviews closely enough to notice.
         */
        if (AuditLog::query()
            ->where('action', 'identity.email_verified')
            ->where('auditable_type', User::class)
            ->where('auditable_id', $user->id)
            ->exists()
        ) {
            return;
        }

        $this->audit->handle(new AuditEntry(
            action: 'identity.email_verified',

            // No actorId: nobody exercised authority here. `actorType` says so
            // explicitly rather than leaving a null to be read as "unknown".
            actorType: 'system',

            auditableType: User::class,
            auditableId: $user->id,
            after: ['email_verified_at' => $user->email_verified_at?->toIso8601String()],
            module: 'identity',
        ));
    }
}
