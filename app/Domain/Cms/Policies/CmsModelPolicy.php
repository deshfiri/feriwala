<?php

namespace App\Domain\Cms\Policies;

use App\Models\User;

/**
 * The CMS's abilities as a Laravel model policy, registered for every model
 * in {@see CmsPolicy::MODELS} — so `$user->can('create', Page::class)`,
 * `Gate::authorize('update', $menu)` and a controller's own check all give
 * the same answer. This class holds no rule of its own, only the mapping
 * from Laravel's ability names to {@see CmsPolicy}.
 */
class CmsModelPolicy
{
    public function viewAny(User $user): bool
    {
        return CmsPolicy::canViewAny($user);
    }

    public function view(User $user): bool
    {
        return CmsPolicy::canViewAny($user);
    }

    public function create(User $user): bool
    {
        return CmsPolicy::canCreate($user);
    }

    public function update(User $user): bool
    {
        return CmsPolicy::canEdit($user);
    }

    public function delete(User $user): bool
    {
        return CmsPolicy::canDelete($user);
    }

    public function publish(User $user): bool
    {
        return CmsPolicy::canPublish($user);
    }

    public function unpublish(User $user): bool
    {
        return CmsPolicy::canUnpublish($user);
    }

    public function archive(User $user): bool
    {
        return CmsPolicy::canArchive($user);
    }
}
