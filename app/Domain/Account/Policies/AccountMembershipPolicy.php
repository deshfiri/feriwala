<?php

namespace App\Domain\Account\Policies;

use App\Domain\Account\Actions\ManageStaff;
use App\Domain\Account\Enums\AccountPermission;
use App\Domain\Account\Enums\AccountRole;
use App\Domain\Account\Models\AccountInvitation;
use App\Domain\Account\Models\AccountMembership;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Account\StaffAllowance;
use App\Models\User;

/**
 * Who may manage the staff of a business account (D1, §8.1, §32).
 *
 * Every question here is answered through `business_account_members`, never
 * through a URL segment or a session value. The actor's own membership decides
 * what they may do, and it decides it against the account the subject belongs
 * to — so a manager of one business gets nothing at all in another, no matter
 * what identifier they put in the request.
 *
 * A package with no staff facility refuses everything. That is not a display
 * concern: an account on a solo package must not be able to invite anyone by
 * posting to the endpoint the hidden button would have used.
 */
class AccountMembershipPolicy
{
    public function __construct(
        protected StaffAllowance $allowance,
    ) {}

    /**
     * See the staff list at all.
     */
    public function viewAny(User $user, BusinessAccount $account): bool
    {
        return $user->belongsToAccount($account)
            && $this->allowance->allowsStaff($account);
    }

    public function invite(User $user, BusinessAccount $account): bool
    {
        return $this->allows($user, $account, AccountPermission::InviteStaff);
    }

    public function revokeInvitation(User $user, AccountInvitation $invitation): bool
    {
        $account = $invitation->businessAccount;

        return $this->allows($user, $account, AccountPermission::RevokeInvitation);
    }

    /**
     * Remove a staff member.
     *
     * The owner is refused here as well as inside {@see ManageStaff},
     * so the button is not offered and the endpoint would not honour it either.
     */
    public function remove(User $user, AccountMembership $membership): bool
    {
        if ($membership->role === AccountRole::Owner) {
            return false;
        }

        return $this->allows(
            $user,
            $membership->businessAccount,
            AccountPermission::RemoveStaff,
        );
    }

    public function changeRole(User $user, AccountMembership $membership): bool
    {
        if ($membership->role === AccountRole::Owner) {
            return false;
        }

        return $this->allows(
            $user,
            $membership->businessAccount,
            AccountPermission::UpdateStaff,
        );
    }

    protected function allows(User $user, BusinessAccount $account, AccountPermission $permission): bool
    {
        if (! $this->allowance->allowsStaff($account)) {
            return false;
        }

        return $user->hasAccountPermission($account, $permission);
    }
}
