<?php

namespace App\Domain\Sourcing\Policies;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Sourcing\Models\ProductSourcingGroup;
use App\Models\User;

/**
 * Who may see and shape Product Sourcing Groups.
 *
 * Final equivalence is a platform-staff decision: Supplier, Client and Partner
 * sessions are separate authenticatables and never reach these checks, and
 * nothing here is deletable -- a group is deactivated, never removed.
 */
class ProductSourcingGroupPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can($this->permission(PermissionAction::View));
    }

    public function view(User $user, ProductSourcingGroup $group): bool
    {
        return $user->can($this->permission(PermissionAction::View));
    }

    public function create(User $user): bool
    {
        return $user->can($this->permission(PermissionAction::Create));
    }

    public function update(User $user, ProductSourcingGroup $group): bool
    {
        return $user->can($this->permission(PermissionAction::Edit));
    }

    /**
     * Activating or deactivating a group.
     */
    public function toggle(User $user, ProductSourcingGroup $group): bool
    {
        return $user->can($this->permission(PermissionAction::Archive));
    }

    protected function permission(PermissionAction $action): string
    {
        return PermissionCatalogue::name(PermissionModule::SourcingGroup, $action);
    }
}
