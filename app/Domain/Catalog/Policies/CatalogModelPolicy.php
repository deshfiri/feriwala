<?php

namespace App\Domain\Catalog\Policies;

use App\Models\User;

/**
 * The catalogue's abilities as a Laravel model policy (§12).
 *
 * Registered for every model in {@see CatalogPolicy::MODELS}, so
 * `$user->can('create', Product::class)`, `Gate::authorize('update', $brand)` and
 * `->can('delete', 'product')` route middleware all give the same answer as the
 * controllers and actions — this class holds no rule of its own, only the
 * mapping from Laravel's ability names to {@see CatalogPolicy}.
 *
 * The record itself never widens an answer: catalogue authority is a platform
 * permission, the same for every product, category and brand.
 */
class CatalogModelPolicy
{
    public function viewAny(User $user): bool
    {
        return CatalogPolicy::canViewAny($user);
    }

    public function view(User $user): bool
    {
        return CatalogPolicy::canViewAny($user);
    }

    public function create(User $user): bool
    {
        return CatalogPolicy::canCreate($user);
    }

    public function update(User $user): bool
    {
        return CatalogPolicy::canEdit($user);
    }

    public function delete(User $user): bool
    {
        return CatalogPolicy::canDelete($user);
    }

    public function archive(User $user): bool
    {
        return CatalogPolicy::canArchive($user);
    }

    public function publish(User $user): bool
    {
        return CatalogPolicy::canPublish($user);
    }

    public function unpublish(User $user): bool
    {
        return CatalogPolicy::canUnpublish($user);
    }

    public function export(User $user): bool
    {
        return CatalogPolicy::canExport($user);
    }
}
