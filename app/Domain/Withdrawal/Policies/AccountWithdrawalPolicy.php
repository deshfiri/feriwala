<?php

namespace App\Domain\Withdrawal\Policies;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Account\Enums\AccountPermission;
use App\Domain\Payout\Policies\PayoutMethodPolicy;
use App\Domain\Supplier\Policies\SupplierWithdrawalPolicy;
use App\Domain\Wallet\Policies\WalletPolicy;
use App\Domain\Withdrawal\Models\AccountWithdrawal;
use App\Models\User;

/**
 * Staff access to a Client/Partner `BusinessAccount`'s withdrawals (§27).
 *
 * Reuses the existing `Module::Withdrawal` permission strings rather than
 * inventing account-specific ones, the same separation of duties the
 * Supplier side already draws ({@see SupplierWithdrawalPolicy}): a staff
 * member with the approve permission may advance and reject, but only one
 * holding the release-payment permission may mark a withdrawal paid.
 *
 * `viewAny`/`view` are admin-only and must never fall back to "this person
 * has a business account" — `Erp\AccountWithdrawalController` never asks
 * this policy at all, because its own query already scopes every read to
 * the signed-in account (self-scoping is a query concern, not a Gate
 * check, mirroring {@see WalletPolicy} and {@see PayoutMethodPolicy}). A
 * business-account bypass here would let any activated account owner into
 * the *staff* withdrawal queue through `Admin\AccountWithdrawalController`,
 * which asks these same two abilities.
 */
class AccountWithdrawalPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can($this->permission(PermissionAction::View));
    }

    public function view(User $user, AccountWithdrawal $withdrawal): bool
    {
        return $user->can($this->permission(PermissionAction::View));
    }

    /**
     * Self-service only: whether this person may request a withdrawal from
     * their own account. `Erp\AccountWithdrawalController` is the only
     * caller — an administrator never "creates" a Client/Partner withdrawal.
     */
    public function create(User $user): bool
    {
        return $this->runsTheBusiness($user);
    }

    public function decide(User $user, AccountWithdrawal $withdrawal): bool
    {
        return $user->can($this->permission(PermissionAction::Approve));
    }

    public function reject(User $user, AccountWithdrawal $withdrawal): bool
    {
        return $user->can($this->permission(PermissionAction::Reject));
    }

    public function releasePayment(User $user, AccountWithdrawal $withdrawal): bool
    {
        return $user->can($this->permission(PermissionAction::ReleasePayment));
    }

    /**
     * Whether this person speaks for the business rather than works in it —
     * the same bar {@see PayoutMethodPolicy}
     * sets for a business-level decision. Requesting a withdrawal moves the
     * account's own money, so it needs the same bar as changing how the
     * account is paid.
     */
    protected function runsTheBusiness(User $user): bool
    {
        return $user->businessAccount !== null
            && ($user->accountRole()?->hasPermission(AccountPermission::UpdateAccount) ?? false);
    }

    protected function permission(PermissionAction $action): string
    {
        return PermissionCatalogue::name(PermissionModule::Withdrawal, $action);
    }
}
