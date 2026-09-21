<?php

namespace App\Domain\Supplier\Policies;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Supplier\Models\SupplierProductListing;
use App\Models\User;

/**
 * Staff access to the Product Listing Request queue (D25, P13-9, P13-11).
 *
 * A Supplier's access to its own listings is query-scoping in the
 * Supplier-guarded controllers, never a Gate check here.
 */
class SupplierProductListingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can($this->permission(PermissionAction::View));
    }

    public function view(User $user, SupplierProductListing $listing): bool
    {
        return $user->can($this->permission(PermissionAction::View));
    }

    /** Requesting a correction, short of a full decision. */
    public function review(User $user, SupplierProductListing $listing): bool
    {
        return $user->can($this->permission(PermissionAction::Review));
    }

    /** Approving, partially approving, or rejecting. */
    public function decide(User $user, SupplierProductListing $listing): bool
    {
        return $user->can($this->permission(PermissionAction::Approve));
    }

    protected function permission(PermissionAction $action): string
    {
        return PermissionCatalogue::name(PermissionModule::SupplierListing, $action);
    }
}
