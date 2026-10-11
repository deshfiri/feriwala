<?php

namespace App\Domain\Sourcing\Policies;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Who may see and change which Suppliers have which Products.
 *
 * Its own `product_supplier` permissions: `view` reads assignments, `create`
 * assigns a Supplier to a Product, `edit` removes one. Platform staff only — a
 * business account is refused whatever it was given.
 */
class ProductSupplierPolicy
{
    public static function canView(User $user): bool
    {
        return self::allows($user, PermissionAction::View);
    }

    public static function canAssign(User $user): bool
    {
        return self::allows($user, PermissionAction::Create);
    }

    public static function canRemove(User $user): bool
    {
        return self::allows($user, PermissionAction::Edit);
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

    protected static function allows(User $user, PermissionAction $action): bool
    {
        return ! $user->accountMembership()->exists()
            && $user->can(PermissionCatalogue::name(PermissionModule::ProductSupplier, $action));
    }
}
