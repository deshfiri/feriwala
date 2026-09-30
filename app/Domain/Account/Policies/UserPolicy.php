<?php

namespace App\Domain\Account\Policies;

use App\Domain\Access\Actions\AssignPlatformRole;
use App\Domain\Access\Enums\PlatformRole;
use App\Models\User;

/**
 * Who may change whether a person can sign in at all (§6, §32).
 *
 * This is the identity question, not the commercial one. Locking a login takes
 * every screen away — administration included — and leaves the business account
 * exactly where it was; {@see BusinessAccountPolicy} governs the other half.
 */
class UserPolicy
{
    /**
     * Whether the actor may lock or unlock this person's sign-in.
     *
     * Two rules on top of the permission:
     *
     * **Nobody locks themselves.** An administrator who does is a support call,
     * and if they held the only account that could undo it, an outage.
     *
     * **Only a Super Admin locks a Super Admin.** Otherwise anybody holding
     * `account.edit` could take the platform's last unrestricted login away, and
     * the recovery from that is a database console. A Super Admin actor never
     * reaches this method — `Gate::before` answers first — which is precisely
     * the case being allowed.
     */
    public function lock(User $actor, User $subject): bool
    {
        if ($actor->is($subject)) {
            return false;
        }

        if ($subject->hasRole(PlatformRole::SuperAdmin->value)) {
            return false;
        }

        return $actor->can('account.edit');
    }

    /**
     * Whether the actor may change which platform role(s) this person holds.
     *
     * **Only a Super Admin changes a Super Admin's roles** — otherwise
     * anybody holding `access.edit` could quietly demote the platform's own
     * unrestricted login. A Super Admin actor never reaches this method;
     * `Gate::before` answers first, which is precisely the case being
     * allowed.
     *
     * Unlike {@see lock()}, a self-change is not refused here: holding
     * `access.edit` already lets someone add a role they have the authority
     * to grant to themselves, and {@see AssignPlatformRole}
     * refuses the one self-change that is actually dangerous — demoting the
     * platform's last active Super Admin — at the point of assignment,
     * where the full before/after role set is known.
     */
    public function assignRole(User $actor, User $subject): bool
    {
        if ($subject->hasRole(PlatformRole::SuperAdmin->value)) {
            return false;
        }

        return $actor->can('access.edit');
    }

    /**
     * Whether the actor may change this *platform staff* member's sign-in
     * status (activate/suspend/deactivate).
     *
     * The same two rules as {@see lock()}, on the same reasoning, gated on
     * `access.edit` instead of `account.edit` -- `lock()` governs a
     * *business owner's* identity, reached from the account dossier, and
     * conflating the two would mean granting `access.edit` (Platform Staff
     * management) accidentally also granted the power to lock a business
     * owner's login, which is a different job under a different permission.
     */
    public function manageStaffAccess(User $actor, User $subject): bool
    {
        if ($actor->is($subject)) {
            return false;
        }

        if ($subject->hasRole(PlatformRole::SuperAdmin->value)) {
            return false;
        }

        return $actor->can('access.edit');
    }
}
