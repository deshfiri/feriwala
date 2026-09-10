<?php

namespace App\Domain\Wallet\Actions;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Wallet\Models\Wallet;
use App\Support\Money\Currency;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Opens a business account's wallet (§23).
 *
 * §23 opens with "Every Active Account will have a Wallet and Financial Ledger",
 * so this runs as part of activation. It is deliberately **idempotent**: an
 * account that somehow reaches activation twice, or a wallet asked for by two
 * requests at once, ends with exactly one — the unique index on
 * `business_account_id` settles the race, and the loser reads back the winner's
 * row rather than failing.
 *
 * A wallet opens **empty**. Every balance starts at zero and moves only through
 * the ledger, so there is no opening entry to explain and no figure that arrived
 * without one.
 */
class OpenWallet
{
    public function handle(BusinessAccount $account, ?Currency $currency = null): Wallet
    {
        $existing = Wallet::query()->where('business_account_id', $account->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return Wallet::create([
                'business_account_id' => $account->id,

                // BDT is the base and operational currency (D4). The column and
                // the argument exist so the schema stays multi-currency-ready.
                'currency_code' => ($currency ?? Currency::BDT)->value,

                /*
                 * Every bucket stated rather than left to the column defaults.
                 * A model that has to be re-read before its own balances are
                 * legible is one a caller will eventually read too early.
                 */
                'total_minor' => 0,
                'required_deposit_minor' => 0,
                'reserved_minor' => 0,
                'pending_minor' => 0,
                'hold_minor' => 0,
                'cod_receivable_minor' => 0,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            $wallet = Wallet::query()->where('business_account_id', $account->id)->first();

            if ($wallet === null) {
                throw $exception;
            }

            return $wallet;
        }
    }
}
