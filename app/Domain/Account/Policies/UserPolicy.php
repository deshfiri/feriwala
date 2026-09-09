<?php

namespace App\Domain\Account\Policies;

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
}
