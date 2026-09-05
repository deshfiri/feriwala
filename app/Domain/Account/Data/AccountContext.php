<?php

namespace App\Domain\Account\Data;

use App\Domain\Account\Enums\AccountPermission;
use App\Domain\Account\Enums\AccountRole;
use App\Domain\Account\StaffAllowance;
use App\Models\User;

/**
 * The one account the signed-in person is working in (D1).
 *
 * Shared on every Inertia response in place of the starter kit's `currentTeam`
 * and `teams` pair. There is no list, because there is nothing to choose
 * between — a person belongs to one business account and cannot switch. The
 * front end renders this as a name, not as a control.
 *
 * `managesStaff` and `allowsStaff` are separate on purpose. The first is about
 * the person (a staff member is not a manager); the second is about the package
 * (a solo plan has no staff facility at all). When either is false the Staff
 * area is absent from the navigation rather than present and disabled — an
 * account that never bought staff should not be shown a door it cannot open.
 */
readonly class AccountContext
{
    public function __construct(
        public string $id,
        public string $name,
        public string $status,
        public string $role,
        public string $roleLabel,
        public bool $isOwner,
        public bool $managesStaff,
        public bool $allowsStaff,
    ) {}

    public static function forUser(User $user, StaffAllowance $allowance): ?self
    {
        $account = $user->businessAccount;
        $role = $user->accountRole();

        // Platform staff have no business account, and that is not a gap to fill
        // with a placeholder — the ERP shell simply has no account to name.
        if ($account === null || $role === null) {
            return null;
        }

        $allowsStaff = $allowance->allowsStaff($account);

        return new self(
            id: $account->public_id,
            name: $account->name,
            status: $account->status->value,
            role: $role->value,
            roleLabel: $role->label(),
            isOwner: $role === AccountRole::Owner,
            managesStaff: $allowsStaff && $role->hasPermission(AccountPermission::InviteStaff),
            allowsStaff: $allowsStaff,
        );
    }
}
