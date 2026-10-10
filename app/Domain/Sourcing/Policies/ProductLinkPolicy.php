<?php

namespace App\Domain\Sourcing\Policies;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Catalog\Policies\CatalogPolicy;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Who may see and shape Same Product links.
 *
 * Its own `product_link` permissions, kept apart from the legacy
 * `sourcing_group` ones so that who may shape groups and who may confirm that
 * two Products are the same are separate decisions: `view` reads links,
 * `create` confirms one, `edit` unlinks or matches variations. Written as static checks (like
 * {@see CatalogPolicy}) because the actions need
 * the same answer the controllers get. Platform staff only: Supplier, Client
 * and Partner sessions are separate authenticatables and never reach these.
 */
class ProductLinkPolicy
{
    public static function canView(User $user): bool
    {
        return $user->can(self::permission(PermissionAction::View));
    }

    public static function canLink(User $user): bool
    {
        return $user->can(self::permission(PermissionAction::Create));
    }

    public static function canUnlink(User $user): bool
    {
        return $user->can(self::permission(PermissionAction::Edit));
    }

    /**
     * @throws AuthorizationException
     */
    public static function authorize(bool $allowed, string $message = 'You may not change Product links.'): void
    {
        if (! $allowed) {
            throw new AuthorizationException($message);
        }
    }

    protected static function permission(PermissionAction $action): string
    {
        return PermissionCatalogue::name(PermissionModule::ProductLink, $action);
    }
}
