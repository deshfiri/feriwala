<?php

namespace App\Domain\Account\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Models\User;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Password;
use InvalidArgumentException;

/**
 * The password-setup invitation for an account staff opened on someone's
 * behalf.
 *
 * Staff never see, choose or hold a password. The person sets their own
 * through the same signed, expiring, single-use link the password-reset flow
 * already issues -- the identity's own broker and token table, so a Supplier's
 * link can never set a Client/Partner password or the reverse.
 *
 * - **One live link at a time.** Issuing replaces any earlier one, so a
 *   resend also revokes what was sent before.
 * - **Single use, expiring.** The broker deletes the token the moment a
 *   password is set and refuses it after `auth.passwords.*.expire` minutes.
 * - **Audited, never leaked.** Every issue and revoke is recorded with the
 *   actor and a mandatory reason; the token itself is never written anywhere
 *   but the person's own notification.
 */
class ManagePasswordSetup
{
    public const USERS = 'users';

    public const SUPPLIERS = 'suppliers';

    public function __construct(
        protected RecordAuditLog $audit,
    ) {}

    /**
     * @param  Model&CanResetPassword  $identity
     * @param  'users'|'suppliers'  $broker
     */
    public function issue(Model $identity, string $broker, User $actor, string $reason): void
    {
        $this->requireReason($reason);

        $token = Password::broker($broker)->createToken($identity);

        $identity->sendPasswordResetNotification($token);

        $this->record('password_setup.sent', $identity, $actor, $reason);
    }

    /**
     * @param  Model&CanResetPassword  $identity
     * @param  'users'|'suppliers'  $broker
     */
    public function revoke(Model $identity, string $broker, User $actor, string $reason): void
    {
        $this->requireReason($reason);

        Password::broker($broker)->deleteToken($identity);

        $this->record('password_setup.revoked', $identity, $actor, $reason);
    }

    protected function requireReason(string $reason): void
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A reason is required and is recorded against this action.');
        }
    }

    protected function record(string $action, Model $identity, User $actor, string $reason): void
    {
        $this->audit->handle(new AuditEntry(
            action: $action,
            actorId: $actor->id,
            auditableType: $identity::class,
            auditableId: $identity->getKey(),
            reason: $reason,
            module: $identity instanceof User ? 'account' : 'supplier',
            isSensitive: true,
        ));
    }
}
