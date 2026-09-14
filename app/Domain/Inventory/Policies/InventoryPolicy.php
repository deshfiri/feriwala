<?php

namespace App\Domain\Inventory\Policies;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Catalog\Policies\CatalogPolicy;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\Warehouse;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Who may read and change central stock (§19).
 *
 * §19: "Only the Admin or an Authorized User can directly modify Central stock."
 * Central stock is Feriwala's, like the catalogue it counts, so the same two
 * questions are asked in the same order:
 *
 *   1. **Is this person a regular user?** Anybody who belongs to a business
 *      account is refused, whatever else they hold — a stray inventory
 *      permission does not let a partner adjust the stock they buy from.
 *   2. **Do they hold the platform permission** for this act: `inventory.view`
 *      to read, `inventory.edit` to hold stock and change it by hand,
 *      `inventory.approve` to override a reservation or change how long one
 *      lasts, `inventory.export` to take figures away.
 *
 * The Gate's `before` hook refuses the same people for every `inventory.*`
 * ability and every inventory model, so a check phrased as a permission name
 * cannot reach a different answer from one phrased through this class.
 */
class InventoryPolicy
{
    /**
     * Every model whose abilities answer to this policy.
     *
     * @var array<int, class-string>
     */
    public const MODELS = [
        Warehouse::class,
        StockItem::class,
    ];

    public static function canViewAny(User $user): bool
    {
        return self::allows($user, PermissionAction::View);
    }

    /**
     * Holding stock and changing it by hand: warehouses, tracked SKUs and
     * adjustments.
     */
    public static function canEdit(User $user): bool
    {
        return self::allows($user, PermissionAction::Edit);
    }

    /**
     * Overriding a reservation, and deciding how long reservations last.
     */
    public static function canApprove(User $user): bool
    {
        return self::allows($user, PermissionAction::Approve);
    }

    public static function canExport(User $user): bool
    {
        return self::allows($user, PermissionAction::Export);
    }

    /**
     * Refuse an action outright unless `$allowed`.
     *
     * For the actions: a controller is not the only way in, and a job or a
     * future order flow reaches the same class.
     *
     * @throws AuthorizationException
     */
    public static function authorize(bool $allowed, string $message = 'This inventory change is not permitted.'): void
    {
        if (! $allowed) {
            throw new AuthorizationException($message);
        }
    }

    /**
     * Whether a Gate check is about inventory: an `inventory.*` permission, or
     * an ability asked of an inventory model or model class.
     *
     * @param  array<int, mixed>  $arguments
     */
    public static function isInventoryAbility(string $ability, array $arguments): bool
    {
        if (str_starts_with($ability, PermissionModule::Inventory->value.'.')) {
            return true;
        }

        $subject = $arguments[0] ?? null;

        foreach (self::MODELS as $model) {
            if ($subject === $model || $subject instanceof $model) {
                return true;
            }
        }

        return false;
    }

    protected static function allows(User $user, PermissionAction $action): bool
    {
        return ! CatalogPolicy::isBusinessIdentity($user)
            && $user->can(PermissionCatalogue::name(PermissionModule::Inventory, $action));
    }
}
