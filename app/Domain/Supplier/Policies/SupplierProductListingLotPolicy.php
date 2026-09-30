<?php

namespace App\Domain\Supplier\Policies;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Supplier\Models\SupplierProductListingLot;
use App\Models\User;

/**
 * Staff access to the listing-lot review queue (Supplier Bulk Product
 * Listing batch) -- the exact same `supplier_listing.*` permissions
 * {@see SupplierProductListingPolicy} already gates a single listing with,
 * since a lot is a batch of the same product entries staff already review
 * one at a time. Never a new permission module: listing review governs
 * listing review, whichever screen it is decided from.
 */
class SupplierProductListingLotPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can($this->permission(PermissionAction::View));
    }

    public function view(User $user, SupplierProductListingLot $lot): bool
    {
        return $user->can($this->permission(PermissionAction::View));
    }

    /** Approving, partially approving, or rejecting one or more entries. */
    public function decide(User $user, SupplierProductListingLot $lot): bool
    {
        return $user->can($this->permission(PermissionAction::Approve));
    }

    protected function permission(PermissionAction $action): string
    {
        return PermissionCatalogue::name(PermissionModule::SupplierListing, $action);
    }
}
