<?php

namespace App\Domain\ContentLibrary\Policies;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Who may read and publish in the Content Library.
 *
 * Platform staff only. Like the catalogue it writes to, belonging to a
 * business account is a refusal rather than a grant: a partner reads published
 * content, and never authors it (§12), whatever role they were given.
 */
class ContentLibraryPolicy
{
    public static function canView(User $user): bool
    {
        return self::allows($user, PermissionAction::View);
    }

    public static function canPublish(User $user): bool
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
     * @throws AuthorizationException
     */
    public static function authorize(bool $allowed, string $message = 'You may not change the Content Library.'): void
    {
        if (! $allowed) {
            throw new AuthorizationException($message);
        }
    }

    protected static function allows(User $user, PermissionAction $action): bool
    {
        return ! $user->accountMembership()->exists()
            && $user->can(PermissionCatalogue::name(PermissionModule::ContentLibrary, $action));
    }
}
