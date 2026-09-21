<?php

namespace App\Domain\Supplier\Policies;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Supplier\Models\SupplierOffer;
use App\Models\User;

/**
 * Staff access to Supplier offers, rates, and catalogue connection (D25,
 * P13-13, P13-14).
 *
 * Everything here is `supplier_pricing.*` — reading or changing a Supplier
 * Rate or a Platform Rate is exactly the confidential-pricing boundary D25
 * draws, and it is never granted to an ordinary catalogue role by default
 * (see `PlatformRole::grants()`).
 *
 * A Supplier's own access to its own offers is query-scoping in the
 * Supplier-guarded controllers, never a Gate check here.
 */
class SupplierOfferPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can($this->permission(PermissionAction::View));
    }

    public function view(User $user, SupplierOffer $offer): bool
    {
        return $user->can($this->permission(PermissionAction::View));
    }

    /** Setting rates, activating, suspending, or choosing the preferred offer. */
    public function edit(User $user, SupplierOffer $offer): bool
    {
        return $user->can($this->permission(PermissionAction::Edit));
    }

    protected function permission(PermissionAction $action): string
    {
        return PermissionCatalogue::name(PermissionModule::SupplierPricing, $action);
    }
}
