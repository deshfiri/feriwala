<?php

namespace App\Domain\Catalog\Policies;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Models\User;

/**
 * Who may touch the central catalogue (§11, §12).
 *
 * §12 is the hard requirement this exists for: **a regular user cannot create or
 * add a product**, a category, a brand, or a variation. That is not a UI
 * decision — hiding a button is not enforcement — so every write path asks here,
 * and here asks the platform permission.
 *
 * A business account holder has no platform permissions at all, so every method
 * below answers no for them without needing a special case. The catalogue is
 * Feriwala's, and a partner selects from it rather than adding to it (§10, §12).
 *
 * Not a model policy: the administration screens list categories and brands
 * before there is a row to ask about, and a model-bound ability has nothing to
 * bind to at that point.
 */
class CatalogPolicy
{
    /**
     * Whether this person may open the catalogue administration screens.
     */
    public static function canViewAny(User $user): bool
    {
        return $user->can(self::permission(PermissionAction::View));
    }

    /**
     * Whether this person may add to the catalogue.
     *
     * §12's first sentence, enforced. Creating a product, a category, a brand or
     * a variation all land here.
     */
    public static function canCreate(User $user): bool
    {
        return $user->can(self::permission(PermissionAction::Create));
    }

    public static function canEdit(User $user): bool
    {
        return $user->can(self::permission(PermissionAction::Edit));
    }

    /**
     * Whether this person may remove a catalogue record outright.
     *
     * Deliberately separate from archiving. Deleting destroys the answer to
     * "what was this product when it was ordered"; archiving keeps it and stops
     * it being chosen again.
     */
    public static function canDelete(User $user): bool
    {
        return $user->can(self::permission(PermissionAction::Delete));
    }

    public static function canArchive(User $user): bool
    {
        return $user->can(self::permission(PermissionAction::Archive));
    }

    /**
     * Whether this person may make a product live, or take it back down.
     *
     * Its own permission, because writing a product and deciding partners may
     * sell it are different decisions — a copywriter is not necessarily
     * somebody who should be able to put stock in front of customers.
     */
    public static function canPublish(User $user): bool
    {
        return $user->can(self::permission(PermissionAction::Publish));
    }

    public static function canUnpublish(User $user): bool
    {
        return $user->can(self::permission(PermissionAction::Unpublish));
    }

    public static function canExport(User $user): bool
    {
        return $user->can(self::permission(PermissionAction::Export));
    }

    protected static function permission(PermissionAction $action): string
    {
        return PermissionCatalogue::name(PermissionModule::Catalog, $action);
    }
}
