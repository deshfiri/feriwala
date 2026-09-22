<?php

namespace App\Domain\Supplier\Policies;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Supplier\Models\SupplierWithdrawal;
use App\Models\User;

/**
 * Staff access to Supplier withdrawals (D25, P13-24).
 *
 * Reuses the existing `Module::Withdrawal` permission strings rather than
 * inventing Supplier-specific ones — the same separation of duties the
 * Client/Partner side already draws: `SupplierManager` may view, advance and
 * reject, but only `WithdrawalApprover` may release a payment
 * ({@see PlatformRole}). A Supplier's own access to
 * its own withdrawals is query-scoping in the Supplier-guarded controllers,
 * never a Gate check here.
 */
class SupplierWithdrawalPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can($this->permission(PermissionAction::View));
    }

    public function view(User $user, SupplierWithdrawal $withdrawal): bool
    {
        return $user->can($this->permission(PermissionAction::View));
    }

    public function decide(User $user, SupplierWithdrawal $withdrawal): bool
    {
        return $user->can($this->permission(PermissionAction::Approve));
    }

    public function reject(User $user, SupplierWithdrawal $withdrawal): bool
    {
        return $user->can($this->permission(PermissionAction::Reject));
    }

    public function releasePayment(User $user, SupplierWithdrawal $withdrawal): bool
    {
        return $user->can($this->permission(PermissionAction::ReleasePayment));
    }

    protected function permission(PermissionAction $action): string
    {
        return PermissionCatalogue::name(PermissionModule::Withdrawal, $action);
    }
}
