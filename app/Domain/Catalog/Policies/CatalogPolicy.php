<?php

namespace App\Domain\Catalog\Policies;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Catalog\Enums\ProductStatus;
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
     * Whether this person may submit a catalogue form at all — creating when
     * `$creating`, editing otherwise.
     *
     * What the form requests ask before validating, so a partner is refused
     * rather than told which fields would have passed. Takes a nullable user
     * because a form request may be reached without one.
     */
    public static function canWrite(mixed $user, bool $creating): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        return $creating ? self::canCreate($user) : self::canEdit($user);
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

    /**
     * Whether this person may make this particular lifecycle move (§11.2, §12).
     *
     * The permission follows what the move does, not which button was pressed:
     *
     *   - to Active is putting it in front of partners — `publish`;
     *   - to Archived is retiring the record — `archive`;
     *   - away from Active or Out of Stock is taking it off sale — `unpublish`;
     *   - everything else (submitting for review, sending back, discontinuing an
     *     inactive product) is authoring — `edit`.
     */
    public static function canMoveProduct(User $user, ProductStatus $from, ProductStatus $to): bool
    {
        return match (true) {
            $to === ProductStatus::Active => self::canPublish($user),
            $to === ProductStatus::Archived => self::canArchive($user),
            in_array($from, [ProductStatus::Active, ProductStatus::OutOfStock], true) => self::canUnpublish($user),
            default => self::canEdit($user),
        };
    }

    /**
     * Whether any status a product could be moved from reaches `$to` by a move
     * this person may make.
     *
     * What a bulk action asks before touching a product, and what the list asks
     * to decide which targets to offer, so the menu and the refusal cannot
     * disagree. Each product is still checked for its own move.
     */
    public static function canMoveAnyProductTo(User $user, ProductStatus $to): bool
    {
        foreach (ProductStatus::lifecycle() as $from) {
            if (in_array($to, $from->transitionsTo(), true) && self::canMoveProduct($user, $from, $to)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether this person holds any permission a bulk action on products uses.
     */
    public static function canActInBulk(mixed $user): bool
    {
        return $user instanceof User
            && (self::canEdit($user) || self::canPublish($user) || self::canUnpublish($user) || self::canArchive($user));
    }

    /**
     * Whether this person may switch a sales channel on or off.
     *
     * Switching on offers the product to every eligible partner on that channel,
     * which is publishing; switching off withdraws it, which is unpublishing.
     */
    public static function canSetChannel(User $user, bool $enabling): bool
    {
        return $enabling ? self::canPublish($user) : self::canUnpublish($user);
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
