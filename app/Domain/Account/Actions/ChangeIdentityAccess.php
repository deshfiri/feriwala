<?php

namespace App\Domain\Account\Actions;

use App\Domain\Access\Actions\AssignPlatformRole;
use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\UserStatus;
use App\Domain\Account\Models\AuthenticatedSession;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Models\User;
use App\Notifications\Account\IdentityClosed;
use App\Notifications\Account\IdentityLocked;
use App\Notifications\Account\IdentitySuspended;
use App\Notifications\Account\IdentityUnlocked;
use App\Support\StateMachine\Exceptions\IllegalStateTransition;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Changes a login's {@see UserStatus} — locks, suspensions, closures, and
 * the moves back to Active (§6).
 *
 * The **identity**, not the business. Losing access here loses every screen
 * there is, administration included, while the person's business account
 * stays exactly where it was — that split is the whole reason `UserStatus`
 * exists separately from `AccountStatus` (D23).
 *
 * A reason is required for every move and is not optional wording. Taking
 * somebody's access away is the kind of decision that gets questioned months
 * later, by which time the person who made it may have left; giving it back is
 * questioned just as hard, and "it was restored" without "because" is not an
 * answer. Neither reason is shown to the person.
 *
 * Nobody may change their own status. An administrator who locks or suspends
 * themselves is a support call, and if theirs was the only login that could
 * undo it, an outage — so it is refused here rather than in the policy, where
 * Super Admin's `Gate::before` would pass straight over it.
 *
 * `Closed` is terminal ({@see UserStatus::isTerminal()}) — there is no move
 * back to `Active` once closed, by design.
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

    /**
     * Suspend a login, ending everywhere it is currently signed in.
     *
     * Unlike a lock, a suspension carries no implication about *why* access
     * was withdrawn — the platform's own vocabulary for platform staff
     * management, kept as a distinct state from `Locked` so an
     * administrator's own audit trail preserves which of the two a given
     * moment actually was.
     *
     * @throws IllegalStateTransition when the identity is closed
     */
    public function suspend(User $subject, User $actor, string $reason): void
    {
        $this->guard($subject, $actor, $reason);
        $this->guardLastActiveSuperAdmin($subject);

        $from = $this->move($subject, UserStatus::Suspended);

        $this->endSessions->all($subject, AuthenticatedSession::ENDED_REVOKED);

        $subject->notify(new IdentitySuspended);

        $this->record('identity.suspended', $subject, $actor, $from, UserStatus::Suspended, $reason);
    }

    /**
     * Permanently close a login. There is no move back to Active once
     * closed ({@see UserStatus::isTerminal()}) — this is the one identity
     * change that cannot be undone by a later action, only by a fresh
     * account.
     *
     * @throws IllegalStateTransition when the identity is already closed
     */
    public function deactivate(User $subject, User $actor, string $reason): void
    {
        $this->guard($subject, $actor, $reason);
        $this->guardLastActiveSuperAdmin($subject);

        $from = $this->move($subject, UserStatus::Closed);

        $this->endSessions->all($subject, AuthenticatedSession::ENDED_REVOKED);

        $subject->notify(new IdentityClosed);

        $this->record('identity.closed', $subject, $actor, $from, UserStatus::Closed, $reason);
    }

    /**
     * Refuses to take away the platform's last *active* Super Admin, the
     * same invariant {@see AssignPlatformRole}
     * protects for a role change — losing access has the identical effect
     * as losing the role.
     */
    protected function guardLastActiveSuperAdmin(User $subject): void
    {
        if (! $subject->hasRole(PlatformRole::SuperAdmin->value)) {
            return;
        }

        $anotherActiveSuperAdminExists = User::role(PlatformRole::SuperAdmin->value)
            ->where('id', '!=', $subject->id)
            ->where('identity_status', UserStatus::Active->value)
            ->exists();

        if (! $anotherActiveSuperAdminExists) {
            throw new InvalidArgumentException('The platform must always have at least one active Super Admin.');
        }
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
