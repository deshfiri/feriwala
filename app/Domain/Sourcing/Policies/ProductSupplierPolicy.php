<?php

namespace App\Domain\Sourcing\Policies;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Who may see and change which Suppliers hold which Products.
 *
 * `product_supplier` decides the screen and the act: `view` reads the Suppliers
 * on a Product, `create` assigns one, `edit` changes or suspends an offer. A
 * Supplier Base Price is the confidential-pricing boundary (D25), so seeing it
 * needs `supplier_pricing.view` and every change to it needs
 * `supplier_pricing.edit` as well. Platform staff only — a business account is
 * refused whatever it was given.
 */
class ProductSupplierPolicy
{
    public static function canView(User $user): bool
    {
        return self::allows($user, PermissionModule::ProductSupplier, PermissionAction::View);
    }

    public static function canViewPrices(User $user): bool
    {
        return self::allows($user, PermissionModule::SupplierPricing, PermissionAction::View);
    }

    public static function canAssign(User $user): bool
    {
        return self::allows($user, PermissionModule::ProductSupplier, PermissionAction::Create)
            && self::allows($user, PermissionModule::SupplierPricing, PermissionAction::Edit);
    }

    public static function canEditOffer(User $user): bool
    {
        return self::allows($user, PermissionModule::ProductSupplier, PermissionAction::Edit)
            && self::allows($user, PermissionModule::SupplierPricing, PermissionAction::Edit);
    }

    /**
     * @throws AuthorizationException
     */
    public static function authorize(bool $allowed, string $message = 'You may not change which Suppliers have a Product.'): void
    {
        if (! $allowed) {
            throw new AuthorizationException($message);
        }
    }

    protected static function allows(User $user, PermissionModule $module, PermissionAction $action): bool
    {
        return ! $user->hasAccountMembership()
            && $user->can(PermissionCatalogue::name($module, $action));
    }
}
