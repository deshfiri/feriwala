<?php

namespace App\Domain\Account;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Supplier\Models\Supplier;
use App\Models\User;

/**
 * Who may delete whom.
 *
 * Deleting an account is the one action here that cannot be taken back, so the
 * rules are stricter than anywhere else and live in one place:
 *
 * - a partner or a partner's staff needs `account.delete`, a Supplier
 *   `supplier.delete` — permissions an administrator hands to whom they trust,
 *   and which Super Admin and Admin hold by default;
 * - nobody deletes their own account, however much authority they hold;
 * - a member of the platform's own staff is deleted only by a Super Admin, so
 *   handing someone `account.delete` never lets them remove the people who
 *   administer the platform.
 */
class DeletionAuthority
{
    public function mayDelete(User $actor, User|Supplier $subject): bool
    {
        return $this->refusal($actor, $subject) === null;
    }

    /**
     * Why `$actor` may not delete `$subject`, or null when they may.
     */
    public function refusal(User $actor, User|Supplier $subject): ?string
    {
        if ($subject instanceof User && $subject->is($actor)) {
            return 'You cannot delete your own account.';
        }

        $module = $subject instanceof Supplier ? PermissionModule::Supplier : PermissionModule::Account;

        if (! $actor->can(PermissionCatalogue::name($module, PermissionAction::Delete))) {
            return 'You do not have permission to delete this account.';
        }

        if ($subject instanceof User && $this->isPlatformStaff($subject) && ! $actor->hasRole(PlatformRole::SuperAdmin->value)) {
            return 'Only a Super Admin can delete a member of the platform staff.';
        }

        return null;
    }

    public function isPlatformStaff(User $user): bool
    {
        return $user->roles()->whereIn('name', array_map(fn (PlatformRole $role) => $role->value, PlatformRole::cases()))->exists();
    }
}
