<?php

namespace App\Domain\Wallet;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Wallet\Exceptions\WalletOperationRefused;
use App\Domain\Wallet\Models\Wallet;
use App\Support\Money\Money;

/**
 * Whether an account has deposited enough to be given a service (§24).
 *
 * §24 opens by listing what a deposit may cover: website setup, domain and
 * hosting registration and renewal, maintenance, package renewal, fulfilment,
 * courier, SMS and platform charges. The common shape is "before we commit
 * something chargeable on your behalf, is the money there".
 *
 * Asked in one place so every caller asks the same question. Two answers are
 * offered on purpose:
 *
 *   - {@see permits()} for a screen, which wants to explain rather than fail;
 *   - {@see assert()} for the moment of commitment, which must refuse.
 *
 * The figures come from the wallet's **captured** obligation, never from the
 * rule table. An account is held to what it agreed to, not to what the policy
 * says this morning.
 *
 * Only the callers that exist today are wired: activation captures the
 * obligation, and the balance sweep acts on it. Website, domain and hosting
 * setup are Phase 5 and later — this is the door they will knock on, and it is
 * tested as such rather than left to be invented then.
 */
class DepositGuard
{
    /**
     * Whether the wallet holds everything §24 asks of it, plus what this
     * particular service is about to cost.
     *
     * The charge is included because meeting the minimum balance and then
     * immediately falling below it is not meeting it — the service would be
     * granted and the account restricted in the same breath.
     */
    public function permits(Wallet $wallet, ?Money $charge = null): bool
    {
        if (! $wallet->meetsObligation()) {
            return false;
        }

        if ($charge === null || ! $charge->isPositive()) {
            return true;
        }

        return $wallet->usableBalance()->greaterThanOrEqualTo($charge);
    }

    /**
     * The same question, at the moment of commitment.
     *
     * @throws WalletOperationRefused
     */
    public function assert(Wallet $wallet, ?Money $charge = null): void
    {
        if ($this->permits($wallet, $charge)) {
            return;
        }

        if (! $wallet->meetsObligation()) {
            throw WalletOperationRefused::depositNotMet(
                $wallet->obligationShortfall(),
                $wallet->reservedObligation(),
            );
        }

        throw WalletOperationRefused::insufficientBalance(
            $charge ?? Money::zero($wallet->currency()),
            $wallet->usableBalance(),
        );
    }

    /**
     * What this account would have to add before the service could go ahead.
     *
     * Zero when nothing stands in the way, so a screen can ask without having to
     * decide what "permitted" means for itself.
     */
    public function shortfallFor(Wallet $wallet, ?Money $charge = null): Money
    {
        $shortfall = $wallet->obligationShortfall();

        if ($charge === null || ! $charge->isPositive()) {
            return $shortfall;
        }

        $spendable = $wallet->usableBalance();

        return $spendable->greaterThanOrEqualTo($charge)
            ? $shortfall
            : $shortfall->plus($charge->minus($spendable));
    }

    /**
     * The account's wallet, or null when it has none.
     */
    public function walletFor(BusinessAccount $account): ?Wallet
    {
        /** @var Wallet|null $wallet */
        $wallet = Wallet::query()->where('business_account_id', $account->id)->first();

        return $wallet;
    }
}
