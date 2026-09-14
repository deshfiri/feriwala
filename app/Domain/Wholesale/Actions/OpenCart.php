<?php

namespace App\Domain\Wholesale\Actions;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Wholesale\Models\Cart;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The signed-in person's wholesale cart, for the account they work in (§14,
 * P4-3).
 *
 * A cart is only ever reached through here, by the person it belongs to, so no
 * request can name somebody else's (§31.3). One cart per person is held by a
 * unique index rather than a check-then-insert, so two first visits racing each
 * other end with one cart, not two.
 *
 * A cart filled for a different business account is discarded rather than
 * repriced: what may be bought, and at what price, is the account's question,
 * and lines one account chose are not lines the next one did.
 */
class OpenCart
{
    /**
     * The person's cart for this account, or null when they have none yet.
     */
    public function find(User $user, BusinessAccount $account): ?Cart
    {
        return Cart::query()
            ->where('user_id', $user->id)
            ->where('business_account_id', $account->id)
            ->first();
    }

    /**
     * The person's cart for this account, opened on first use.
     *
     * @throws UniqueConstraintViolationException when a racing request opened a cart for another account
     */
    public function forUser(User $user, BusinessAccount $account): Cart
    {
        if ($cart = $this->find($user, $account)) {
            return $cart;
        }

        try {
            return DB::transaction(function () use ($user, $account) {
                // A cart left from another account goes, lines and all.
                Cart::query()
                    ->where('user_id', $user->id)
                    ->where('business_account_id', '!=', $account->id)
                    ->delete();

                return Cart::create([
                    'user_id' => $user->id,
                    'business_account_id' => $account->id,
                ]);
            });
        } catch (UniqueConstraintViolationException $exception) {
            // The racing request opened it first; its cart answers both.
            return $this->find($user, $account) ?? throw $exception;
        }
    }
}
