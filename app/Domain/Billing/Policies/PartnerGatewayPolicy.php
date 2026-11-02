<?php

namespace App\Domain\Billing\Policies;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Account\Enums\AccountPermission;
use App\Domain\Website\Models\Website;
use App\Models\User;

/**
 * Who may see and change a Partner's own payment gateway (D36).
 *
 * Static checks rather than a registered model policy, like
 * {@see BillingSettingsPolicy}: `Gate::before` answers yes to a Super Admin for
 * every ability a policy would be asked, and this must not. A Partner's gateway
 * credentials are the Partner's alone, so the decision is made here, from the
 * signed-in person's own business account and never from anything a request
 * names, and no platform role can pass it.
 *
 * Platform staff may see **that** a Storefront uses a Partner gateway, through
 * `payment.view`. They never configure it, and nobody is ever shown the secret.
 */
class PartnerGatewayPolicy
{
    /**
     * Whether this person may set up, change or switch off the gateway of a
     * Storefront: they speak for the business that owns it.
     */
    public static function canConfigure(User $user, Website $website): bool
    {
        $account = $user->businessAccount;

        return $account !== null
            && $account->id === $website->business_account_id
            && ($user->accountRole()?->hasPermission(AccountPermission::UpdateAccount) ?? false);
    }

    /**
     * Whether this person may see how a Storefront's gateway is configured:
     * which provider, which mode, which credentials are present — never a value.
     */
    public static function canViewStatus(User $user, Website $website): bool
    {
        $account = $user->businessAccount;

        if ($account !== null) {
            return $account->id === $website->business_account_id;
        }

        return $user->can(PermissionCatalogue::name(PermissionModule::Payment, PermissionAction::View));
    }
}
