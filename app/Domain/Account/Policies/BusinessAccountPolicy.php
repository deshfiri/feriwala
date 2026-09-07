<?php

namespace App\Domain\Account\Policies;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Account\Models\BusinessAccount;
use App\Models\User;

/**
 * Who may look at business accounts, and who may decide on them (§32, D23).
 *
 * Seeing the approval queue and approving from it are separate permissions. A
 * support agent should be able to answer "where has my application got to"
 * without being able to let anyone onto the platform.
 *
 * The subject is the account; the actor is a person. "Their own" therefore means
 * an account they are a member of — an owner must not approve their own
 * business, and neither must a staff member they invited.
 */
class BusinessAccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can($this->permission(PermissionAction::View));
    }

    public function view(User $user, BusinessAccount $account): bool
    {
        if ($user->belongsToAccount($account)) {
            return true;
        }

        return $user->can($this->permission(PermissionAction::View));
    }

    /**
     * Reading the administrator's view of an account (§7.2, §32).
     *
     * Deliberately **not** {@see view()}. That one lets a member see their own
     * account, which is right for a screen written for them; an administrative
     * screen is written for somebody else and carries internal reasons,
     * staff-only notes and reviewer metadata beside the facts. §7.2 forbids that
     * material reaching the applicant, and a policy that admits members would
     * hand it to them through their own account's URL.
     *
     * So membership is a refusal here rather than a grant, exactly as it is for
     * approving one's own activation.
     */
    public function viewDossier(User $user, BusinessAccount $account): bool
    {
        if ($user->belongsToAccount($account)) {
            return false;
        }

        return $user->can($this->permission(PermissionAction::View));
    }

    /**
     * Approving an activation is the moment a business gains the run of the
     * platform (§5.1, §44), so it is its own permission — and never one's own.
     */
    public function approveActivation(User $user, BusinessAccount $account): bool
    {
        if ($user->belongsToAccount($account)) {
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
    public function requestKycResubmission(User $user, BusinessAccount $account): bool
    {
        return $this->approveActivation($user, $account);
    }

    /**
     * Suspension is a separate permission from approval, on purpose.
     *
     * It removes a business's ability to trade — and its invited staff lose the
     * ERP with it — while §5.3 keeps it reversible and closure is what is not.
     * It must never become a quiet route to permanent denial in the hands of
     * anyone who happens to work the approval queue; closure belongs to the
     * closure and retention workflow (D18).
     */
    public function suspend(User $user, BusinessAccount $account): bool
    {
        if ($user->belongsToAccount($account)) {
            return false;
        }

        return $user->can($this->permission(PermissionAction::Reject));
    }

    protected function permission(PermissionAction $action): string
    {
        return PermissionCatalogue::name(PermissionModule::Account, $action);
    }
}
