<?php

namespace App\Domain\Settings\Policies;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Models\User;

/**
 * Who may change the platform's logo and browser icon.
 *
 * `system.manage_settings`, held by the System Administrator and — through the
 * override — the Super Admin. The brand is the platform's, shown to every
 * partner and every visitor, so it sits with the people who configure the
 * platform rather than with any module's managers. One permission guards both
 * the screen and every write: there is nothing on the screen for someone who
 * cannot change it.
 */
class BrandingPolicy
{
    public static function canManage(User $user): bool
    {
        return $user->can(PermissionCatalogue::name(PermissionModule::System, PermissionAction::ManageSettings));
    }
}
