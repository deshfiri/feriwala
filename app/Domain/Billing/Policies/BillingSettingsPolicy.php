<?php

namespace App\Domain\Billing\Policies;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Models\User;

/**
 * Who may set what Feriwala charges (§9, §32).
 *
 * Fee rules, coupons and tax rules are one decision with three shapes: they all
 * change the amount on somebody's invoice, and none of them is a package
 * question or an account question. They sit behind `payment.manage_settings`,
 * which is what the Payment Manager role holds — the person who reconciles the
 * money is the person who should be able to price it.
 *
 * Deliberately not `package.edit`. Writing a plan and pricing the fee charged
 * alongside it are different jobs, and a Package Manager who could also move the
 * registration fee would be able to change the total without touching anything
 * the package screen shows.
 *
 * Not a model policy: these are configuration, and Laravel's model-bound
 * abilities would need a row to ask about before anybody could see the screen
 * that lists them.
 */
class BillingSettingsPolicy
{
    /**
     * Whether this person may see the billing configuration screens.
     */
    public static function canView(User $user): bool
    {
        return $user->can(self::permission(PermissionAction::View));
    }

    /**
     * Whether this person may change what is charged.
     */
    public static function canManage(User $user): bool
    {
        return $user->can(self::permission(PermissionAction::ManageSettings));
    }

    protected static function permission(PermissionAction $action): string
    {
        return PermissionCatalogue::name(PermissionModule::Payment, $action);
    }
}
