<?php

namespace App\Domain\Address\Policies;

use App\Domain\Account\Enums\AccountPermission;
use App\Domain\Address\Enums\AddressOwnerType;
use App\Domain\Address\Models\SharedAddress;
use App\Domain\Website\Policies\WebsitePolicy;
use App\Http\Controllers\Supplier\AddressController;
use App\Http\Controllers\Supplier\PayoutMethodController;
use App\Models\User;

/**
 * Who may see and change a `BusinessAccount`'s own address book (§31.3).
 *
 * The Client/Partner (`web` guard) side only — mirrors {@see WebsitePolicy}.
 * The Supplier side is never routed through a policy:
 * {@see AddressController} scopes every query to the signed-in Supplier's
 * own guard identity inline, the same way {@see PayoutMethodController}
 * already does — there is no "other Supplier's session" a policy would need
 * to refuse that the guard boundary does not already refuse (D25).
 */
class SharedAddressPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->businessAccount !== null;
    }

    public function view(User $user, SharedAddress $address): bool
    {
        return $this->belongsToAccountOf($user, $address);
    }

    public function create(User $user): bool
    {
        return $this->runsTheBusiness($user);
    }

    public function update(User $user, SharedAddress $address): bool
    {
        return $this->belongsToAccountOf($user, $address) && $this->runsTheBusiness($user);
    }

    public function archive(User $user, SharedAddress $address): bool
    {
        return $this->update($user, $address);
    }

    public function setDefault(User $user, SharedAddress $address): bool
    {
        return $this->update($user, $address);
    }

    protected function belongsToAccountOf(User $user, SharedAddress $address): bool
    {
        $account = $user->businessAccount;

        return $account !== null && $address->ownedBy(AddressOwnerType::BusinessAccount, $account->id);
    }

    /**
     * Whether this person speaks for the business rather than works in it —
     * the same bar {@see WebsitePolicy} sets for
     * a business-level decision.
     */
    protected function runsTheBusiness(User $user): bool
    {
        return $user->businessAccount !== null
            && ($user->accountRole()?->hasPermission(AccountPermission::UpdateAccount) ?? false);
    }
}
