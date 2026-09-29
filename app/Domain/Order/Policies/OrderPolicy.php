<?php

namespace App\Domain\Order\Policies;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Order\Enums\AllocationSourceType;
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
     * Open the staff source-comparison panel for one line.
     *
     * `order.edit` alone answers "may this person move the order", not
     * "should this person see a Supplier's confidential rate or the
     * platform's own cost and margin" — a wider question the allocation
     * batch's first cut answered by accident rather than by design. Every
     * candidate row carries all four at once (Supplier identity, Supplier
     * Rate, warehouse cost, platform margin), so viewing the comparison at
     * all requires every permission that covers what it shows — never a
     * partial, redacted list a staff member could mistake for the whole
     * picture and allocate from anyway.
     */
    public function viewAllocationSources(User $user, Order $order): bool
    {
        return $this->transition($user, $order)
            && $user->can(PermissionCatalogue::name(PermissionModule::SupplierPricing, PermissionAction::View))
            && $user->can(PermissionCatalogue::name(PermissionModule::Catalog, PermissionAction::View));
    }

    /**
     * Commit a line to the source a member of staff chose.
     *
     * `order.edit` is the transition authority every allocation needs
     * regardless of source; which figure it was compared against decides
     * the second permission — a Supplier offer's confidential rate needs
     * `supplier_pricing.view`, Central Warehouse's own cost needs
     * `catalog.view`. Checked again here, independently of
     * {@see viewAllocationSources()}: a request that never opened the
     * panel must not be able to allocate a source it was never shown.
     */
    public function allocateSource(User $user, Order $order, AllocationSourceType $sourceType): bool
    {
        if (! $this->transition($user, $order)) {
            return false;
        }

        return match ($sourceType) {
            AllocationSourceType::SupplierOffer => $user->can(PermissionCatalogue::name(PermissionModule::SupplierPricing, PermissionAction::View)),
            AllocationSourceType::Warehouse => $user->can(PermissionCatalogue::name(PermissionModule::Catalog, PermissionAction::View)),
        };
    }

    /**
     * Confirm that a Supplier offer or warehouse stock item, catalogued under
     * a different product, fulfils this order line's own product (D25,
     * Order Allocation correction batch).
     *
     * Deliberately independent of `order.edit`: this is a catalogue-curation
     * decision (the relationship holds for every future order of this
     * product, not just this one), reusing `catalog.edit` -- the existing
     * permission for changing what the catalogue says -- rather than a new
     * one. A Supplier-side relationship additionally needs
     * `supplier_pricing.view`, the same figure-ownership boundary
     * {@see allocateSource()} already draws, so confirming a link to a
     * Supplier's offer is never possible for someone who may not even see
     * that Supplier's rate.
     */
    public function confirmSourceLink(User $user, Order $order, AllocationSourceType $sourceType): bool
    {
        if (! $user->can(PermissionCatalogue::name(PermissionModule::Catalog, PermissionAction::Edit))) {
            return false;
        }

        return match ($sourceType) {
            AllocationSourceType::SupplierOffer => $user->can(PermissionCatalogue::name(PermissionModule::SupplierPricing, PermissionAction::View)),
            AllocationSourceType::Warehouse => true,
        };
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
