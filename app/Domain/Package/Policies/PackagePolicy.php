<?php

namespace App\Domain\Package\Policies;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Package\Models\Package;
use App\Models\User;

/**
 * Who may shape what Feriwala sells (§8.1, §32).
 *
 * Creating a package sets prices, limits and facilities for every account that
 * buys it, so it sits behind `package.create` and `package.edit` rather than a
 * general administrative permission. Retiring one is separate again
 * (`package.archive`): it can strand verification rules and subscriptions, and
 * the person who writes package copy is not necessarily the person who should
 * be able to withdraw a plan.
 *
 * The public catalogue is not governed here. An applicant choosing a package
 * reads {@see Package::scopePubliclyListed()}, which answers "is this on sale",
 * not "may you administer it".
 */
class PackagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can($this->permission(PermissionAction::View));
    }

    public function view(User $user, Package $package): bool
    {
        return $user->can($this->permission(PermissionAction::View));
    }

    public function create(User $user): bool
    {
        return $user->can($this->permission(PermissionAction::Create));
    }

    /**
     * An archived package is a historical record, not configuration.
     *
     * Editing one would change what accounts that bought it appear to have
     * bought — refused here as well as in the action, so the control is not
     * offered either.
     */
    public function update(User $user, Package $package): bool
    {
        return ! $package->trashed() && $user->can($this->permission(PermissionAction::Edit));
    }

    public function archive(User $user, Package $package): bool
    {
        return ! $package->trashed() && $user->can($this->permission(PermissionAction::Archive));
    }

    /**
     * Granting an account a package without a sale (§8.3).
     *
     * `package.manage_settings`, not `package.create`. Writing a plan and giving
     * one away are different decisions with different consequences: the first
     * changes what is on offer, the second hands a specific business real
     * entitlement for nothing, and the roles that shape a catalogue are not
     * automatically the roles that should be able to do that.
     */
    public function assign(User $user, Package $package): bool
    {
        return ! $package->trashed()
            && $user->can($this->permission(PermissionAction::ManageSettings));
    }

    /**
     * Hard deletion is deliberately absent.
     *
     * §8 has packages archived, never removed: a payment, a subscription and an
     * invoice all name the package they were for, and a row that vanishes takes
     * the meaning of those records with it (§36.2).
     */
    public function delete(User $user, Package $package): bool
    {
        return false;
    }

    protected function permission(PermissionAction $action): string
    {
        return PermissionCatalogue::name(PermissionModule::Package, $action);
    }
}
