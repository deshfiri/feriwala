<?php

namespace App\Domain\Supplier\Policies;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Kyc\Policies\KycSubmissionPolicy;
use App\Domain\Supplier\Models\Supplier;
use App\Models\User;

/**
 * Who among staff may see and decide on Supplier applications (D25, P13-1).
 *
 * Nothing here authorizes a Supplier's own access to its own record — a
 * Supplier is never a `User` and this policy is never asked about one. Self
 * access is a query-scoping concern in the Supplier-guarded controllers
 * (§31.3-equivalent), not a Gate check.
 */
class SupplierPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can($this->permission(PermissionAction::View));
    }

    public function view(User $user, Supplier $supplier): bool
    {
        return $user->can($this->permission(PermissionAction::View));
    }

    /**
     * Approving or rejecting a KYC round — the two outcomes of one decision,
     * exactly as {@see KycSubmissionPolicy::review()}
     * covers both with one permission.
     */
    public function decide(User $user): bool
    {
        return $user->can($this->permission(PermissionAction::Approve));
    }

    public function suspend(User $user, Supplier $supplier): bool
    {
        return $user->can($this->permission(PermissionAction::Suspend));
    }

    public function reactivate(User $user, Supplier $supplier): bool
    {
        return $user->can($this->permission(PermissionAction::Suspend));
    }

    /**
     * Opening a Supplier account on a Supplier's behalf.
     */
    public function create(User $user): bool
    {
        return $user->can($this->permission(PermissionAction::Create));
    }

    /**
     * Changing a Supplier's non-immutable profile and managing their
     * password-setup invitation.
     */
    public function update(User $user, Supplier $supplier): bool
    {
        return $user->can($this->permission(PermissionAction::Edit));
    }

    /**
     * Manually confirming a Supplier's email or mobile.
     */
    public function verify(User $user, Supplier $supplier): bool
    {
        return $user->can($this->permission(PermissionAction::Verify));
    }

    protected function permission(PermissionAction $action): string
    {
        return PermissionCatalogue::name(PermissionModule::Supplier, $action);
    }
}
