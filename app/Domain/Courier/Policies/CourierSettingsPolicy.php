<?php

namespace App\Domain\Courier\Policies;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Models\User;

/**
 * Who may configure a courier integration: `courier.manage_integrations`, held
 * by the Courier Manager. One permission for seeing the screen and changing it,
 * because the screen is where keys are entered and a callback token is issued.
 *
 * Configuration, not a model policy -- there is no row to ask about.
 */
class CourierSettingsPolicy
{
    public static function canManage(User $user): bool
    {
        return $user->can(PermissionCatalogue::name(PermissionModule::Courier, PermissionAction::ManageIntegrations));
    }
}
