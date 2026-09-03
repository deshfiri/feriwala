<?php

namespace App\Domain\Account\Policies;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Models\User;

/**
 * Who may look at accounts, and who may decide on them (§32).
 *
 * Seeing the approval queue and approving from it are separate permissions. A
 * support agent should be able to answer "where has my application got to"
 * without being able to let anyone onto the platform.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can($this->permission(PermissionAction::View));
    }

    public function view(User $user, User $account): bool
    {
        if ($account->is($user)) {
            return true;
        }

        return $user->can($this->permission(PermissionAction::View));
    }

    /**
     * Approving an activation is the moment someone gains the run of the
     * platform (§5.1, §44), so it is its own permission — and never one's own.
     */
    public function approveActivation(User $user, User $account): bool
    {
        if ($account->is($user)) {
            return false;
        }

        return $user->can($this->permission(PermissionAction::Approve));
    }

    /**
     * Sending an applicant back for corrections sits with approval.
     *
     * A reviewer who can let someone in should be able to ask them to fix a
     * document first — the alternative is a queue whose only options are yes or
     * escalate, which produces approvals that should not have happened.
     */
    public function requestKycResubmission(User $user, User $account): bool
    {
        return $this->approveActivation($user, $account);
    }

    /**
     * Suspension is a separate permission from approval, on purpose.
     *
     * It removes someone's ability to trade, and §5.3 keeps it reversible while
     * closure is terminal — so it must not become a quiet route to permanent
     * denial in the hands of anyone who happens to work the approval queue.
     * Permanent closure belongs to the closure and retention workflow (D18).
     */
    public function suspend(User $user, User $account): bool
    {
        if ($account->is($user)) {
            return false;
        }

        return $user->can($this->permission(PermissionAction::Reject));
    }

    protected function permission(PermissionAction $action): string
    {
        return PermissionCatalogue::name(PermissionModule::Account, $action);
    }
}
