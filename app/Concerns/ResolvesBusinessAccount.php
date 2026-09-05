<?php

namespace App\Concerns;

use App\Domain\Account\Models\BusinessAccount;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Resolves the business account behind the current request (D23).
 *
 * Commercial screens need an account, not a person. The signed-in user has at
 * most one, so there is no context to switch and no `{current_team}` to carry in
 * the URL — which is what P1-65 removes.
 *
 * A platform staff member reaching a commercial screen has no account, and that
 * is not a 500. It means they are somewhere that does not apply to them, so it
 * is a 403: the business ERP is not theirs to use, however much of the admin
 * panel they may open.
 */
trait ResolvesBusinessAccount
{
    /**
     * @throws HttpException when the signed-in user has no business account
     */
    protected function businessAccountFor(Request $request): BusinessAccount
    {
        $account = $this->currentBusinessAccount($request);

        abort_if($account === null, 403, __('This area is for business accounts.'));

        return $account;
    }

    /**
     * The account, or null when the signed-in user has none.
     *
     * For screens that render differently rather than refusing.
     */
    protected function currentBusinessAccount(Request $request): ?BusinessAccount
    {
        $user = $request->user();

        return $user instanceof User ? $user->businessAccount : null;
    }
}
