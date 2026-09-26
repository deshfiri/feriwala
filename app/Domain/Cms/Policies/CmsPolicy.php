<?php

namespace App\Domain\Cms\Policies;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Catalog\Policies\CatalogPolicy;
use App\Domain\Cms\Models\Menu;
use App\Domain\Cms\Models\MenuItem;
use App\Domain\Cms\Models\Page;
use App\Domain\Cms\Models\Redirect;
use App\Models\User;

/**
 * Who may touch the public landing page and its supporting CMS records
 * (§4, §34). Every model this governs — the page itself, its menus, its
 * redirects — answers to the same `cms.*` permission set
 * (`PermissionModule::Cms`), so one class holds the rule rather than five
 * near-identical policies repeating the same check. Global SEO defaults are
 * deliberately not one of them: {@see SeoSettingPolicy}
 * governs those under the separate `seo.*` set, matching the permission
 * catalogue's own split between the two modules. The media library is not
 * one of them either, as of the Stage 7 addendum: {@see CmsMediaPolicy}
 * governs it under its own `cms.media.*` verbs, since browsing or uploading
 * an asset is a different authority from writing section copy.
 *
 * Unlike {@see CatalogPolicy}, there is no
 * cross-cutting exclusion to apply here: nothing about the public landing
 * page has a parallel "my own" meaning for a business or Supplier account,
 * so an ordinary permission check is the whole rule.
 */
class CmsPolicy
{
    /**
     * Every model whose abilities answer to this policy.
     *
     * @var array<int, class-string>
     */
    public const MODELS = [
        Page::class,
        Menu::class,
        MenuItem::class,
        Redirect::class,
    ];

    public static function canViewAny(User $user): bool
    {
        return self::allows($user, PermissionAction::View);
    }

    public static function canCreate(User $user): bool
    {
        return self::allows($user, PermissionAction::Create);
    }

    public static function canEdit(User $user): bool
    {
        return self::allows($user, PermissionAction::Edit);
    }

    public static function canDelete(User $user): bool
    {
        return self::allows($user, PermissionAction::Delete);
    }

    /**
     * Publishing, scheduling and restoring a revision all move a page
     * toward being live, so they share one ability.
     */
    public static function canPublish(User $user): bool
    {
        return self::allows($user, PermissionAction::Publish);
    }

    public static function canUnpublish(User $user): bool
    {
        return self::allows($user, PermissionAction::Unpublish);
    }

    public static function canArchive(User $user): bool
    {
        return self::allows($user, PermissionAction::Archive);
    }

    /**
     * Whether this person may browse the media library — to pick an
     * existing asset for a section or an SEO image, never to upload one.
     */
    public static function canViewMedia(User $user): bool
    {
        return self::allows($user, PermissionAction::MediaView);
    }

    /**
     * Whether this person may upload, edit or remove a media asset.
     */
    public static function canManageMedia(User $user): bool
    {
        return self::allows($user, PermissionAction::MediaManage);
    }

    protected static function allows(User $user, PermissionAction $action): bool
    {
        return $user->can(PermissionCatalogue::name(PermissionModule::Cms, $action));
    }
}
