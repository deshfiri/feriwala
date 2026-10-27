<?php

namespace App\Domain\Cases\Policies;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Account\Enums\AccountPermission;
use App\Domain\Cases\Models\CaseEvidence;
use App\Models\User;

/**
 * Who may open a piece of case evidence (D35, §31.3).
 *
 *   - **An unsent draft** is its uploader's alone — not their colleagues', not staff.
 *   - **Evidence on a case** is the Partner account's whose case it is, for someone
 *     in that account who may read its orders, and staff who may see that kind of
 *     case: `return.view`, `exchange.view` or `damage.view`.
 *
 * Another Partner is never in either list, whatever id or link they hold: the
 * check is made against the signed-in person's own account on every request, and
 * a signed link only says the link was issued, not that the person may use it.
 */
class CaseEvidencePolicy
{
    public function view(User $user, CaseEvidence $evidence): bool
    {
        if ($evidence->isDraft()) {
            return $evidence->uploader_id === $user->id;
        }

        $account = $user->businessAccount;

        if ($account !== null) {
            return $account->id === $evidence->business_account_id
                && ($user->accountRole()?->hasPermission(AccountPermission::ViewOrders) ?? false);
        }

        if ($evidence->order_damage_id !== null) {
            return $user->can(PermissionCatalogue::name(PermissionModule::Damage, PermissionAction::View));
        }

        $evidence->loadMissing('orderReturn');

        $module = $evidence->orderReturn?->isExchange() === true ? PermissionModule::Exchange : PermissionModule::ReturnCase;

        return $user->can(PermissionCatalogue::name($module, PermissionAction::View));
    }

    /**
     * Removing is the uploader's, and only while it is a draft.
     */
    public function delete(User $user, CaseEvidence $evidence): bool
    {
        return $evidence->isDraft() && $evidence->uploader_id === $user->id;
    }
}
