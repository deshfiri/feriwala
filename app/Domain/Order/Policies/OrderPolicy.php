<?php

namespace App\Domain\Order\Policies;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Order\Models\Order;
use App\Models\User;

/**
 * Who may see and act on an order (§18.4, §18.5, §31.3).
 *
 * Two audiences, kept apart:
 *
 *   - **The account that placed it** sees its own orders and nobody else's. That
 *     is decided from the signed-in person's own business account, never from
 *     anything a request names.
 *   - **Platform staff** see and move orders through the order permissions their
 *     role holds (`order.view`, `order.edit`).
 *
 * A person who trades on the platform never holds platform order administration,
 * whatever has been handed to them: the ordered `Gate::before` refuses `order.*`
 * abilities to a business identity, as it refuses the catalogue, so a partner
 * given `order.view` still cannot read another account's orders.
 */
class OrderPolicy
{
    /** Platform order abilities start with this. */
    public const ABILITY_PREFIX = 'order.';

    public static function isOrderAdministrationAbility(string $ability): bool
    {
        return str_starts_with($ability, self::ABILITY_PREFIX);
    }

    /**
     * The platform's order list.
     */
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionCatalogue::name(PermissionModule::Order, PermissionAction::View));
    }

    public function view(User $user, Order $order): bool
    {
        return $this->placedByAccountOf($user, $order)
            || $user->can(PermissionCatalogue::name(PermissionModule::Order, PermissionAction::View));
    }

    /**
     * Move an order's status by hand. Platform staff only: an account holder's
     * own orders move through what they do — paying, cancelling — not through a
     * status control.
     */
    public function transition(User $user, Order $order): bool
    {
        return $user->can(PermissionCatalogue::name(PermissionModule::Order, PermissionAction::Edit));
    }

    /**
     * Cancel an order nobody has paid for: the account that placed it, or staff
     * who may move orders.
     */
    public function cancel(User $user, Order $order): bool
    {
        return $this->placedByAccountOf($user, $order) || $this->transition($user, $order);
    }

    protected function placedByAccountOf(User $user, Order $order): bool
    {
        $account = $user->businessAccount;

        return $account !== null && $account->id === $order->business_account_id;
    }
}
