<?php

namespace App\Domain\Account\Actions;

use App\Domain\Account\Enums\UserStatus;
use App\Domain\Account\Models\AuthenticatedSession;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Models\User;
use App\Notifications\Account\IdentityLocked;
use App\Notifications\Account\IdentityUnlocked;
use App\Support\StateMachine\Exceptions\IllegalStateTransition;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Locks and unlocks a login (§6).
 *
 * The **identity**, not the business. A locked person loses every screen there
 * is, administration included, while their business account stays exactly where
 * it was — that split is the whole reason {@see UserStatus} exists separately
 * from `AccountStatus` (D23).
 *
 * A reason is required in both directions and is not optional wording. Taking
 * somebody's access away is the kind of decision that gets questioned months
 * later, by which time the person who made it may have left; giving it back is
 * questioned just as hard, and "it was restored" without "because" is not an
 * answer. Neither reason is shown to the person.
 *
 * Nobody may lock themselves. An administrator who does is a support call, and
 * if theirs was the only login that could undo it, an outage — so it is refused
 * here rather than in the policy, where Super Admin's `Gate::before` would pass
 * straight over it.
 */
class ChangeIdentityAccess
{
    public function __construct(
        protected EndAuthenticatedSessions $endSessions,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * Lock a login, ending everywhere it is currently signed in.
     *
     * @throws IllegalStateTransition when the identity is not in a state that
     *                                can be locked — a closed account, say
     */
    public function lock(User $subject, User $actor, string $reason): void
    {
        $this->guard($subject, $actor, $reason);

        $from = $this->move($subject, UserStatus::Locked);

        /*
         * A lock that leaves an open tab working until the person happens to
         * reload is not a lock. The identity gate would catch them on the next
         * request either way; ending the sessions means there is no next request
         * to depend on.
         */
        $this->endSessions->all($subject, AuthenticatedSession::ENDED_REVOKED);

        $subject->notify(new IdentityLocked);

        $this->record('identity.locked', $subject, $actor, $from, UserStatus::Locked, $reason);
    }

    /**
     * Restore a locked login.
     *
     * @throws IllegalStateTransition when the identity is suspended or closed —
     *                                those are lifted by their own workflows,
     *                                not by unlocking
     */
    public function unlock(User $subject, User $actor, string $reason): void
    {
        $this->guard($subject, $actor, $reason);

        $from = $this->move($subject, UserStatus::Active);

        $subject->notify(new IdentityUnlocked);

        $this->record('identity.unlocked', $subject, $actor, $from, UserStatus::Active, $reason);
    }

    protected function guard(User $subject, User $actor, string $reason): void
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'Changing sign-in access requires a recorded reason.'
            );
        }

        if ($subject->is($actor)) {
            throw new InvalidArgumentException(
                'Sign-in access cannot be changed for the person making the change.'
            );
        }
    }

    /**
     * Move the identity, under a row lock so two administrators deciding at
     * once resolve to one winner rather than to two history entries.
     */
    protected function move(User $subject, UserStatus $to): UserStatus
    {
        return $this->database->transaction(function () use ($subject, $to) {
            /** @var User $locked */
            $locked = User::query()->lockForUpdate()->findOrFail($subject->id);

            $from = $locked->identity_status;

            // Throws when the move is not declared legal, before anything is
            // written.
            $locked->transitionTo($to);
            $locked->forceFill(['identity_status_changed_at' => now()])->save();

            $subject->setRawAttributes($locked->getAttributes(), sync: true);

            return $from;
        });
    }

    protected function record(
        string $action,
        User $subject,
        User $actor,
        UserStatus $from,
        UserStatus $to,
        string $reason,
    ): void {
        $this->audit->handle(new AuditEntry(
            action: $action,
            actorId: $actor->id,
            auditableType: User::class,
            auditableId: $subject->id,
            before: ['identity_status' => $from->value],
            after: ['identity_status' => $to->value],
            reason: $reason,
            module: 'identity',
            // Removing or restoring somebody's access is exactly what an
            // investigation goes looking for.
            isSensitive: true,
        ));
    }
}
