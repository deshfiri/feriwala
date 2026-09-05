<?php

namespace App\Domain\Account\Enums;

use App\Domain\Access\Enums\PlatformRole;

/**
 * A person's role inside one business account (D1, §32).
 *
 * The user-facing vocabulary is **Account, Staff and Permissions**: the team
 * concept the starter kit shipped with must not surface in a route, a label or
 * a message. This enum is the account-side role, distinct from
 * {@see PlatformRole}, which is what Feriwala's own
 * staff hold.
 *
 * Three, deliberately. Anything finer belongs to the permission catalogue
 * rather than to a role ladder nobody can hold in their head.
 */
enum AccountRole: string
{
    /**
     * The person who registered the business. Exactly one per account, and not
     * removable or demotable through ordinary staff management (D1) — an
     * account whose owner can be removed by an administrator they invited is
     * one keystroke from having nobody who can pay for it.
     */
    case Owner = 'owner';

    /** May manage staff and account settings, but is still staff. */
    case Manager = 'manager';

    /** Works in the account. Holds no staff-management rights. */
    case Staff = 'staff';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Manager => 'Manager',
            self::Staff => 'Staff',
        };
    }

    /**
     * Whether this role may invite, remove and re-role other staff.
     */
    public function managesStaff(): bool
    {
        return $this === self::Owner || $this === self::Manager;
    }

    /**
     * What this role may do inside the account.
     *
     * The owner holds everything, which is not the same as being unstoppable:
     * ManageStaff refuses to remove or demote them regardless of permission,
     * because that is a property of the account rather than of a grant.
     *
     * @return array<int, AccountPermission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => AccountPermission::cases(),
            self::Manager => [
                AccountPermission::InviteStaff,
                AccountPermission::UpdateStaff,
                AccountPermission::RemoveStaff,
                AccountPermission::RevokeInvitation,
            ],
            self::Staff => [],
        };
    }

    public function hasPermission(AccountPermission $permission): bool
    {
        return in_array($permission, $this->permissions(), strict: true);
    }

    /**
     * Roles an invitation may be issued for.
     *
     * Owner is absent: an account has one owner, established at registration,
     * and there is no invitation that makes a second.
     *
     * @return array<int, self>
     */
    public static function invitable(): array
    {
        return [self::Manager, self::Staff];
    }

    /**
     * Whether this role counts against the package's staff limit (§8.1).
     *
     * The owner does not. They pay for the package; charging them a seat in it
     * would mean a limit of one allowed nobody but themselves.
     */
    public function consumesStaffSeat(): bool
    {
        return $this !== self::Owner;
    }
}
