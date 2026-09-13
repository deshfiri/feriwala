<?php

namespace App\Domain\Catalog\Policies;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductAttribute;
use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Domain\Catalog\Models\ProductMedia;
use App\Domain\Catalog\Models\ProductVariant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Who may touch the central catalogue (§11, §12).
 *
 * §12 is the hard requirement this exists for: **a regular user cannot create or
 * add a product**, a category, a brand, or a variation. That is not a UI
 * decision — hiding a button is not enforcement — so every write path asks here:
 * the controllers, the form requests, the actions themselves, the model policies
 * registered for every catalogue model ({@see CatalogModelPolicy}), and the
 * Gate's own `before` hook. They cannot disagree, because they are all this
 * class.
 *
 * Two questions, in this order:
 *
 *   1. **Is this person a regular user?** Anybody who belongs to a business
 *      account is, whatever else they hold. Membership is a refusal, not a
 *      grant — a stray platform permission, or even the Super Admin role, given
 *      to someone who trades on the platform does not let them author the
 *      catalogue they sell from, or read the base cost behind it.
 *   2. **Do they hold the platform permission** for this particular act?
 *
 * Not a model policy on its own: the administration screens list categories and
 * brands before there is a row to ask about. {@see CatalogModelPolicy} wraps it
 * for `Gate` and route middleware.
 */
class CatalogPolicy
{
    /**
     * Every model whose abilities answer to this policy.
     *
     * @var array<int, class-string>
     */
    public const MODELS = [
        Product::class,
        ProductVariant::class,
        Category::class,
        Brand::class,
        ProductAttribute::class,
        ProductAttributeValue::class,
        ProductMedia::class,
    ];

    /**
     * Whether this person may open the catalogue administration screens.
     *
     * Viewing is refused to regular users too: those screens carry the base cost
     * and internal eligibility decisions a partner is never shown.
     */
    public static function canViewAny(User $user): bool
    {
        return self::allows($user, PermissionAction::View);
    }

    /**
     * Whether this person may add to the catalogue.
     *
     * §12's first sentence, enforced. Creating a product, a category, a brand or
     * a variation all land here.
     */
    public static function canCreate(User $user): bool
    {
        return self::allows($user, PermissionAction::Create);
    }

    public static function canEdit(User $user): bool
    {
        return self::allows($user, PermissionAction::Edit);
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
     * Whether this person holds any permission that writes the catalogue.
     *
     * The coarse question, asked before a request is validated wherever the
     * precise permission depends on what the request says — so a person with no
     * write permission at all meets a 403, never a list of field errors.
     */
    public static function canWriteAny(mixed $user): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        foreach ([
            PermissionAction::Create, PermissionAction::Edit, PermissionAction::Delete,
            PermissionAction::Archive, PermissionAction::Publish, PermissionAction::Unpublish,
        ] as $action) {
            if (self::allows($user, $action)) {
                return true;
            }
        }

        return false;
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
        return self::allows($user, PermissionAction::Delete);
    }

    public static function canArchive(User $user): bool
    {
        return self::allows($user, PermissionAction::Archive);
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
        return self::allows($user, PermissionAction::Publish);
    }

    public static function canUnpublish(User $user): bool
    {
        return self::allows($user, PermissionAction::Unpublish);
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
        return self::allows($user, PermissionAction::Export);
    }

    /**
     * Refuse an action outright unless `$allowed`.
     *
     * For the actions: a controller is not the only way in, and a bulk action,
     * a job or a future API reaches the same class.
     *
     * @throws AuthorizationException
     */
    public static function authorize(bool $allowed, string $message = 'This catalogue change is not permitted.'): void
    {
        if (! $allowed) {
            throw new AuthorizationException($message);
        }
    }

    /**
     * Whether this person trades on the platform — a regular user in §12's sense.
     *
     * Asked of the database every time, deliberately uncached. A static cache
     * outlives the request that filled it, and a stale "yes" would lock staff
     * out of the catalogue while a stale "no" would let a partner in. The query
     * is an indexed `exists`, and it never loads the relation onto the model,
     * so nothing extra is serialised into the shared `auth.user` prop.
     */
    public static function isBusinessIdentity(User $user): bool
    {
        return $user->accountMembership()->exists();
    }

    /**
     * Whether a Gate check is about the catalogue: a `catalog.*` permission, or
     * an ability asked of a catalogue model or model class.
     *
     * @param  array<int, mixed>  $arguments
     */
    public static function isCatalogueAbility(string $ability, array $arguments): bool
    {
        if (str_starts_with($ability, PermissionModule::Catalog->value.'.')) {
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
        return ! self::isBusinessIdentity($user) && $user->can(self::permission($action));
    }

    protected static function permission(PermissionAction $action): string
    {
        return PermissionCatalogue::name(PermissionModule::Catalog, $action);
    }
}
