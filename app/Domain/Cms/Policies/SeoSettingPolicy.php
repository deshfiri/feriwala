<?php

namespace App\Domain\Cms\Policies;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Models\User;

/**
 * Who may see and change the global SEO defaults every page's own override
 * falls back to (§34). Deliberately its own permission set (`seo.*`), not
 * `cms.*` — the permission catalogue's `SeoManager` role holds full control
 * of these defaults while only being able to view and edit ordinary page
 * content, and `ContentManager` is the reverse: full control of pages, but
 * only view/edit here.
 */
class SeoSettingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can($this->permission(PermissionAction::View));
    }

    public function view(User $user): bool
    {
        return $user->can($this->permission(PermissionAction::View));
    }

    public function update(User $user): bool
    {
        return $user->can($this->permission(PermissionAction::Edit));
    }

    protected function permission(PermissionAction $action): string
    {
        return PermissionCatalogue::name(PermissionModule::Seo, $action);
    }
}
