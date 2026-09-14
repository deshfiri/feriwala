<?php

namespace App\Domain\Wholesale\Actions;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Wholesale\Models\CartItem;
use App\Domain\Wholesale\Queries\PriceCart;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The person has seen the prices as they are today (§14, P4-5).
 *
 * Remembers each line's current unit price as the one they were shown, so the
 * "price changed" notice clears. It changes nothing that is charged: the price
 * charged is always the one the server works out at the time.
 */
class AcceptCartPrices
{
    public function __construct(
        protected OpenCart $carts,
        protected PriceCart $pricing,
    ) {}

    public function handle(User $user, BusinessAccount $account): int
    {
        $cart = $this->carts->find($user, $account);
        $quote = $this->pricing->quote($cart, $account);
        $accepted = 0;

        DB::transaction(function () use ($quote, &$accepted) {
            foreach ($quote->lines as $line) {
                if ($line->unitPrice === null || ! $line->priceChanged) {
                    continue;
                }

                CartItem::query()
                    ->whereKey($line->item->id)
                    ->update(['unit_price_seen_minor' => $line->unitPrice->minorUnits]);

                $accepted++;
            }
        });

        return $accepted;
    }
}
