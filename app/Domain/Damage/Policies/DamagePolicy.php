<?php

namespace App\Domain\Damage\Policies;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Account\Enums\AccountPermission;
use App\Domain\Damage\Models\OrderDamage;
use App\Domain\Order\Enums\ReturnParty;
use App\Models\User;

/**
 * Who may see and act on a damage case (D35, §31.3).
 *
 * Two audiences, kept apart exactly as the return cases keep them:
 *
 *   - **The Partner whose order it is** sees their own damages and nobody
 *     else's, decided from the signed-in person's own business account and never
 *     from anything a request names. This is query scoping by account, not a
 *     permission: the `damage.view_self` and `damage.request_delivery` verbs name
 *     what this does for a Partner, but a Partner is a business identity, and the
 *     ordered `Gate::before` refuses every `damage.*` ability to one — so the
 *     check is made here, from ownership and the account's own role.
 *   - **Platform staff** see cases through the `damage.*` permissions their role
 *     holds.
 */
class DamagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionCatalogue::name(PermissionModule::Damage, PermissionAction::View));
    }

    public function view(User $user, OrderDamage $damage): bool
    {
        return $this->ownedBy($user, $damage)
            || $user->can(PermissionCatalogue::name(PermissionModule::Damage, PermissionAction::View));
    }

    /**
     * Whether the signed-in Partner may read this damage as their own.
     */
    public function ownedBy(User $user, OrderDamage $damage): bool
    {
        $account = $user->businessAccount;

        return $account !== null
            && $account->id === $damage->business_account_id
            && ($user->accountRole()?->hasPermission(AccountPermission::ViewOrders) ?? false);
    }

    /**
     * Whether the signed-in Partner may ask for this item to be delivered: their
     * own, and speaking for the business — it spends the business's money.
     */
    public function requestDelivery(User $user, OrderDamage $damage): bool
    {
        $account = $user->businessAccount;

        return $account !== null
            && $account->id === $damage->business_account_id
            && $damage->responsible_party === ReturnParty::Partner
            && ($user->accountRole()?->hasPermission(AccountPermission::UpdateAccount) ?? false);
    }
}
