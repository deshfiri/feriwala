<?php

namespace App\Domain\Account\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * A password staff set for a Client/Partner owner who could not finish the
 * setup link themselves.
 *
 * The setup link ({@see ManagePasswordSetup}) stays the normal route. This is
 * the fallback: staff choose a password, hand it to the person, and the person
 * signs in with it.
 *
 * - **Never kept in the clear.** The `hashed` cast stores only the hash; the
 *   password is never written to the audit log, a flash message or a page.
 * - **Audited with a reason.** Who set it, for whom and why, as a sensitive
 *   entry.
 * - **Remembered logins end.** The remember token is replaced, so a browser
 *   signed in with the old password does not stay signed in for weeks.
 */
class SetPasswordByStaff
{
    public function __construct(
        protected RecordAuditLog $audit,
    ) {}

    public function handle(User $owner, string $password, User $actor, string $reason): void
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A reason is required and is recorded against this action.');
        }

        $owner->forceFill([
            'password' => $password,
            'remember_token' => Str::random(60),
        ])->save();

        $this->audit->handle(new AuditEntry(
            action: 'password.set_by_staff',
            actorId: $actor->id,
            auditableType: $owner::class,
            auditableId: $owner->getKey(),
            reason: $reason,
            module: 'account',
            isSensitive: true,
        ));
    }
}
