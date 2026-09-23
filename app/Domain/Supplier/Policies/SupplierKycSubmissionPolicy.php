<?php

namespace App\Domain\Supplier\Policies;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Supplier\Models\SupplierKycSubmission;
use App\Models\User;

/**
 * Staff access to the Supplier KYC queue (D25, P13-7).
 *
 * Seeing the queue and deciding it are separate permissions.
 * {@see review()} covers only requesting a correction — approving or
 * rejecting is the stronger `supplier.approve` ability on
 * {@see SupplierPolicy::decide()}, since that
 * decision is really about the Supplier, not the queue row.
 */
class SupplierKycSubmissionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can($this->permission(PermissionAction::View));
    }

    public function view(User $user, SupplierKycSubmission $submission): bool
    {
        return $user->can($this->permission(PermissionAction::View));
    }

    public function review(User $user, SupplierKycSubmission $submission): bool
    {
        return $user->can($this->permission(PermissionAction::Review));
    }

    protected function permission(PermissionAction $action): string
    {
        return PermissionCatalogue::name(PermissionModule::SupplierKyc, $action);
    }
}
