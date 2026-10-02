<?php

namespace App\Domain\Storage\Policies;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Billing\Policies\BillingSettingsPolicy;
use App\Models\User;

/**
 * Who may see and change the Cloudflare R2 storage configuration
 * (beta-critical batch, Commit 3).
 *
 * Reuses the existing `integration.*` permissions ({@see
 * PermissionModule::Integration}) rather than inventing a storage-specific
 * module -- R2 is one more third-party integration to configure, the exact
 * shape that module already exists for, and `SystemAdministrator` already
 * holds all three verbs on it.
 *
 * Mirrors {@see BillingSettingsPolicy} exactly:
 * viewing the masked settings is separate from being able to save
 * credentials or run the live connection test, since both of those touch
 * secrets.
 */
class StorageSettingsPolicy
{
    public static function canView(User $user): bool
    {
        return $user->can(self::permission(PermissionAction::View));
    }

    /**
     * Whether this person may save credentials, switch R2 on/off, or run the
     * live connection test -- all three touch the stored secret.
     */
    public static function canManage(User $user): bool
    {
        return $user->can(self::permission(PermissionAction::ManageIntegrations));
    }

    protected static function permission(PermissionAction $action): string
    {
        return PermissionCatalogue::name(PermissionModule::Integration, $action);
    }
}
