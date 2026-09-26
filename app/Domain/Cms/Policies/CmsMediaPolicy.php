<?php

namespace App\Domain\Cms\Policies;

use App\Domain\Cms\Models\Media;
use App\Models\User;

/**
 * The CMS media library's abilities as a Laravel model policy (Stage 7
 * addendum). Registered for {@see Media} instead of
 * {@see CmsModelPolicy} — browsing or uploading an asset is `cms.media.*`,
 * a different authority from writing a page's own content, which is why an
 * SEO Manager can hold this without holding ordinary CMS edit rights, and a
 * Content Manager can hold CMS edit without this.
 */
class CmsMediaPolicy
{
    public function viewAny(User $user): bool
    {
        return CmsPolicy::canViewMedia($user);
    }

    public function view(User $user): bool
    {
        return CmsPolicy::canViewMedia($user);
    }

    public function create(User $user): bool
    {
        return CmsPolicy::canManageMedia($user);
    }

    public function update(User $user): bool
    {
        return CmsPolicy::canManageMedia($user);
    }

    public function delete(User $user): bool
    {
        return CmsPolicy::canManageMedia($user);
    }
}
