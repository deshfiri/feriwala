<?php

namespace App\Domain\ExternalStore\Policies;

use App\Domain\Account\Enums\AccountPermission;
use App\Domain\ExternalStore\Models\ExternalStore;
use App\Models\User;

/**
 * Who may see and run an external store connection (D29).
 *
 * Only the account that owns it, decided from the signed-in person's own
 * business account and never from anything a request names — and inside that
 * account, only someone holding `store:manage`. Platform staff have no reason
 * to hold a partner's store keys and are given no ability here.
 */
class ExternalStorePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->managesStores($user);
    }

    public function view(User $user, ExternalStore $store): bool
    {
        return $this->belongsToAccountOf($user, $store) && $this->managesStores($user);
    }

    public function create(User $user): bool
    {
        return $this->managesStores($user);
    }

    public function manage(User $user, ExternalStore $store): bool
    {
        return $this->belongsToAccountOf($user, $store) && $this->managesStores($user);
    }

    protected function belongsToAccountOf(User $user, ExternalStore $store): bool
    {
        $account = $user->businessAccount;

        return $account !== null && $account->id === $store->business_account_id;
    }

    protected function managesStores(User $user): bool
    {
        return $user->businessAccount !== null
            && ($user->accountRole()?->hasPermission(AccountPermission::ManageStores) ?? false);
    }
}
