<?php

namespace App\Domain\Notification\Policies;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Models\User;

/**
 * Who may configure SMS (§30, §32).
 *
 * `sms.manage_settings`, held by the SMS Manager. Turning messaging off, or
 * switching which provider carries it, decides whether customers hear about
 * their own payments — which is why it is its own permission rather than
 * something anybody who can send a notification also holds.
 *
 * Not a model policy: these are configuration, and a model-bound ability would
 * need a row to ask about before anybody could see the screen that lists them.
 */
class SmsSettingsPolicy
{
    public static function canView(User $user): bool
    {
        return $user->can(self::permission(PermissionAction::View));
    }

    public static function canManage(User $user): bool
    {
        return $user->can(self::permission(PermissionAction::ManageSettings));
    }

    protected static function permission(PermissionAction $action): string
    {
        return PermissionCatalogue::name(PermissionModule::Sms, $action);
    }
}
