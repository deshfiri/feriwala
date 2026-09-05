<?php

namespace App\Domain\Account\Actions;

use App\Domain\Account\Enums\AccountRole;
use App\Domain\Account\Models\AccountInvitation;
use App\Domain\Account\Models\AccountMembership;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Revoking invitations, removing staff and changing their role (D1).
 *
 * The owner is not reachable through any of it. D1 makes them the account, and
 * an account whose owner can be removed or demoted by a manager they invited is
 * one keystroke away from having nobody who can pay for it or answer for it.
 * Transferring ownership is a different thing with different consequences, and
 * is not an ordinary staff-management action.
 */
class ManageStaff
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * Withdraw an invitation before it is used. The seat is freed at once.
     */
    public function revoke(AccountInvitation $invitation, User $by): void
    {
        $this->database->transaction(function () use ($invitation, $by) {
            /** @var AccountInvitation|null $locked */
            $locked = AccountInvitation::query()->lockForUpdate()->find($invitation->id);

            if ($locked === null || ! $locked->isLive()) {
                throw new InvalidArgumentException('This invitation is no longer open.');
            }

            $locked->forceFill([
                'revoked_at' => now(),
                'revoked_by' => $by->id,
            ])->save();

            $this->audit->handle(new AuditEntry(
                action: 'account.staff_invitation_revoked',
                actorId: $by->id,
                auditableType: AccountInvitation::class,
                auditableId: $locked->id,
                after: ['email' => $locked->email],
                accountId: $locked->business_account_id,
                module: 'account',
            ));

            $invitation->setRawAttributes($locked->getAttributes(), sync: true);
        });
    }

    /**
     * Remove a staff member. Never the owner.
     */
    public function remove(AccountMembership $membership, User $by): void
    {
        $this->database->transaction(function () use ($membership, $by) {
            $this->refuseOnOwner($membership, 'removed');

            $this->audit->handle(new AuditEntry(
                action: 'account.staff_removed',
                actorId: $by->id,
                auditableType: AccountMembership::class,
                auditableId: $membership->id,
                before: ['role' => $membership->role->value],
                accountId: $membership->business_account_id,
                module: 'account',
            ));

            $membership->delete();
        });
    }

    /**
     * Change what a staff member may do. Never the owner's role.
     */
    public function changeRole(AccountMembership $membership, AccountRole $role, User $by): void
    {
        if ($role === AccountRole::Owner) {
            throw new InvalidArgumentException(
                'Ownership is not granted through staff management.'
            );
        }

        $this->database->transaction(function () use ($membership, $role, $by) {
            $this->refuseOnOwner($membership, 'given a different role');

            $from = $membership->role;

            $membership->forceFill(['role' => $role])->save();

            $this->audit->handle(new AuditEntry(
                action: 'account.staff_role_changed',
                actorId: $by->id,
                auditableType: AccountMembership::class,
                auditableId: $membership->id,
                before: ['role' => $from->value],
                after: ['role' => $role->value],
                accountId: $membership->business_account_id,
                module: 'account',
            ));
        });
    }

    protected function refuseOnOwner(AccountMembership $membership, string $verb): void
    {
        if ($membership->role === AccountRole::Owner) {
            throw new InvalidArgumentException(
                "The account owner cannot be {$verb} through staff management."
            );
        }
    }
}
