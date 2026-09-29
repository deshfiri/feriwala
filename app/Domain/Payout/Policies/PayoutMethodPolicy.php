<?php

namespace App\Domain\Payout\Policies;

use App\Domain\Account\Enums\AccountPermission;
use App\Domain\Address\Policies\SharedAddressPolicy;
use App\Domain\Payout\Enums\PayoutOwnerType;
use App\Domain\Payout\Models\PayoutMethod;
use App\Domain\Website\Policies\WebsitePolicy;
use App\Models\User;

/**
 * Who may see and change a `BusinessAccount`'s own payout methods (§31.3).
 *
 * The Client/Partner (`web` guard) side only — mirrors
 * {@see SharedAddressPolicy}. The Supplier side
 * is never routed through a policy: `Supplier\PayoutMethodController` scopes
 * every query to the signed-in Supplier's own guard identity inline — there
 * is no "other Supplier's session" a policy would need to refuse that the
 * guard boundary does not already refuse (D25).
 */
class PayoutMethodPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->businessAccount !== null;
    }

    public function view(User $user, PayoutMethod $method): bool
    {
        return $this->belongsToAccountOf($user, $method);
    }

    public function create(User $user): bool
    {
        return $this->runsTheBusiness($user);
    }

    public function update(User $user, PayoutMethod $method): bool
    {
        return $this->belongsToAccountOf($user, $method) && $this->runsTheBusiness($user);
    }

    public function archive(User $user, PayoutMethod $method): bool
    {
        return $this->update($user, $method);
    }

    public function setDefault(User $user, PayoutMethod $method): bool
    {
        return $this->update($user, $method);
    }

    protected function belongsToAccountOf(User $user, PayoutMethod $method): bool
    {
        $account = $user->businessAccount;

        return $account !== null && $method->ownedBy(PayoutOwnerType::BusinessAccount, $account->id);
    }

    /**
     * Whether this person speaks for the business rather than works in it —
     * the same bar {@see WebsitePolicy} sets
     * for a business-level decision.
     */
    protected function runsTheBusiness(User $user): bool
    {
        return $user->businessAccount !== null
            && ($user->accountRole()?->hasPermission(AccountPermission::UpdateAccount) ?? false);
    }
}
