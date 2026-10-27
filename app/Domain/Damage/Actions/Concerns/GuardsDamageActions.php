<?php

namespace App\Domain\Damage\Actions\Concerns;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Account\Enums\AccountPermission;
use App\Domain\Damage\Models\OrderDamage;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The checks every damage action starts from (D35): who is asking, and the case
 * locked and loaded so the decision is taken on what it is now.
 */
trait GuardsDamageActions
{
    /**
     * @throws AuthorizationException
     */
    protected function authorizeStaff(User $actor, PermissionAction $action, string $what): void
    {
        if (! $actor->can(PermissionCatalogue::name(PermissionModule::Damage, $action))) {
            throw new AuthorizationException('This account may not '.$what.'.');
        }
    }

    /**
     * The Partner whose damage this is, speaking for the business — or nobody.
     *
     * @throws AuthorizationException
     */
    protected function authorizeOwner(User $actor, OrderDamage $damage, bool $mustRunTheBusiness, string $what): void
    {
        $account = $actor->businessAccount;
        $role = $actor->accountRole();

        $mayAct = $mustRunTheBusiness
            ? ($role?->hasPermission(AccountPermission::UpdateAccount) ?? false)
            : ($role?->hasPermission(AccountPermission::ViewOrders) ?? false);

        if ($account === null || $account->id !== $damage->business_account_id || ! $mayAct) {
            throw new AuthorizationException('This account may not '.$what.'.');
        }
    }

    /**
     * The case, locked for the rest of the transaction.
     */
    protected function locked(OrderDamage $damage): OrderDamage
    {
        /** @var OrderDamage $locked */
        $locked = OrderDamage::query()->lockForUpdate()->findOrFail($damage->id);
        $locked->load(['orderItem.stockReservation.partnerStock', 'orderItem.product', 'orderItem.variant', 'businessAccount']);

        return $locked;
    }
}
