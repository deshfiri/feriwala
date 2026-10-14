<?php

namespace App\Domain\Audit;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Catalog\Policies\CatalogPolicy;
use App\Models\User;

/**
 * Who may remove activity-log entries, directly or through retention (D33, as
 * restricted on 2026-10-14).
 *
 * **The Super Admin, and nobody else.** Holding `audit.delete` or
 * `audit.manage_settings` is necessary but not sufficient: a custom role that
 * was given either permission still cannot clear the log, because erasing the
 * record of what people did is not a right that should travel with a role
 * someone else can edit. A business identity is refused whatever it holds.
 *
 * Every caller — the controllers and the actions behind them — asks here, so
 * the rule cannot be met at the screen and missed in the action.
 */
class AuditLogAuthority
{
    public static function mayPurge(User $user): bool
    {
        return self::superAdminHolding($user, PermissionAction::Delete);
    }

    public static function mayManageRetention(User $user): bool
    {
        return self::superAdminHolding($user, PermissionAction::ManageSettings);
    }

    protected static function superAdminHolding(User $user, PermissionAction $action): bool
    {
        return ! CatalogPolicy::isBusinessIdentity($user)
            && $user->hasRole(PlatformRole::SuperAdmin->value)
            && $user->can(PermissionCatalogue::name(PermissionModule::Audit, $action));
    }
}
