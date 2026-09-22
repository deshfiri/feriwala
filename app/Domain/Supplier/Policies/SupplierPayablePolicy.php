<?php

namespace App\Domain\Supplier\Policies;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Supplier\Models\SupplierPayable;
use App\Models\User;

/**
 * Staff access to Supplier payables (D25, P13-22).
 *
 * Viewing and settlement are deliberately separate permission strings —
 * `supplier_payable.view` and `supplier_payable.approve` — so a role can see
 * what is owed without holding the (future, P13-23) right to move money for
 * it. A Supplier's own access to its own payables is query-scoping in the
 * Supplier-guarded controllers, never a Gate check here.
 */
class SupplierPayablePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can($this->permission(PermissionAction::View));
    }

    public function view(User $user, SupplierPayable $payable): bool
    {
        return $user->can($this->permission(PermissionAction::View));
    }

    protected function permission(PermissionAction $action): string
    {
        return PermissionCatalogue::name(PermissionModule::SupplierPayable, $action);
    }
}
