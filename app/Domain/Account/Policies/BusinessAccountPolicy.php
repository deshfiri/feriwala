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
     * Declining an applicant at the activation gate (§5.3).
     *
     * Scoped to the approval queue, and kept on `account.reject` because that
     * is what it is: a decision about someone who has never traded. Suspending
     * a business that **is** trading is a different and larger power — see
     * {@see suspendTrading()} — and must not ride on the queue's permission.
     */
    public function suspend(User $user, BusinessAccount $account): bool
    {
        if ($user->belongsToAccount($account)) {
            return false;
        }

        return $user->can($this->permission(PermissionAction::Reject));
    }

    /**
     * Stopping a business that is already trading (§5.3).
     *
     * Its own permission, `account.suspend`, because the consequences are not
     * the queue's: the business stops selling, its invited staff lose the ERP,
     * and its websites stop taking orders. Whoever works the approval queue
     * should not thereby be able to halt a live business.
     *
     * Sensitive, so the §32.2 escalation applies on top of holding it.
     */
    public function suspendTrading(User $user, BusinessAccount $account): bool
    {
        if ($user->belongsToAccount($account)) {
            return false;
        }

        return $user->can($this->permission(PermissionAction::Suspend));
    }

    /**
     * Lifting a suspension (§5.3).
     *
     * Separately grantable from `account.suspend` so a narrow role can be
     * given neither — but the default roles hold both, because a role that can
     * stop a business and not restart it turns a reversible decision into a
     * permanent one in practice, which is what §5.3 and D18 keep apart.
     */
    public function reactivate(User $user, BusinessAccount $account): bool
    {
        if ($user->belongsToAccount($account)) {
            return false;
        }

        return $user->can($this->permission(PermissionAction::Reactivate));
    }

    protected function permission(PermissionAction $action): string
    {
        return PermissionCatalogue::name(PermissionModule::Account, $action);
    }
}
