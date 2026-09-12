<?php

namespace App\Domain\Wallet\Actions;

use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Wallet\Data\TopUpPlan;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletDepositObligation;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Validation\ValidationException;

/**
 * What a top-up of this size would do, and whether it is allowed (§24, P2-19).
 *
 * Every figure here is produced on the server from the wallet's **captured**
 * obligation. Nothing is taken from the request but the amount, and even that is
 * checked against what the account is actually required to hold rather than
 * against anything the page was rendered with (§36.1).
 *
 * The split matters to the person paying. Money into a wallet that is short does
 * two jobs: the first part closes the shortfall and has to stay, the rest is
 * spendable immediately. Telling somebody that before they pay is the difference
 * between a top-up and a surprise.
 *
 * The purpose follows from the same arithmetic rather than from a radio button.
 * A payment that closes any part of a deposit shortfall **is** a deposit; one
 * that does not is a top-up. §26.3 keeps them apart and so does the ledger:
 * DepositCredit or TopUpCredit, and a statement that says which.
 */
class PlanWalletTopUp
{
    /**
     * The smallest top-up worth taking a payment for.
     *
     * Not a policy, a floor: a gateway charges a fee per transaction and a
     * one-taka top-up costs more to process than it adds. §24.1's configured
     * required top-up overrides it whenever there is one.
     */
    public const FLOOR_MINOR = 100;

    /**
     * Work out what this amount would do.
     *
     * @throws ValidationException when the amount is not one this account may pay
     */
    public function handle(Wallet $wallet, int $amountMinor): TopUpPlan
    {
        $currency = Currency::from($wallet->currency_code);
        $amount = Money::of($amountMinor, $currency);

        $minimum = $this->minimumFor($wallet, $currency);

        if (! $amount->isPositive()) {
            throw ValidationException::withMessages([
                'amount_minor' => __('wallet.top_up.errors.not_positive'),
            ]);
        }

        if ($amount->lessThan($minimum)) {
            throw ValidationException::withMessages([
                'amount_minor' => __('wallet.top_up.errors.below_minimum', [
                    'amount' => $minimum->format(),
                ]),
            ]);
        }

        $shortfall = $wallet->obligationShortfall();

        // The part that closes what is owed, and the part that does not.
        $toObligation = $amount->lessThan($shortfall) ? $amount : $shortfall;
        $toUsable = $amount->minus($toObligation);

        return new TopUpPlan(
            amount: $amount,
            toObligation: $toObligation,
            toUsable: $toUsable,
            minimum: $minimum,

            /*
             * A payment that closes any part of the **deposit** is a deposit.
             * The minimum balance is not a deposit — it is money the account
             * keeps — so a shortfall of that alone is still a top-up.
             */
            purpose: $wallet->shortfall()->isPositive()
                ? PaymentPurpose::WalletDeposit
                : PaymentPurpose::WalletTopUp,
        );
    }

    /**
     * The least this account may pay in right now.
     *
     * §24.1's required top-up where one is configured, and otherwise the floor
     * below which a payment costs more to take than it is worth.
     */
    public function minimumFor(Wallet $wallet, ?Currency $currency = null): Money
    {
        $currency ??= Currency::from($wallet->currency_code);

        $required = $this->obligation($wallet)?->required_top_up_minor;

        return $required !== null && $required->isPositive()
            ? $required
            : Money::of(self::FLOOR_MINOR, $currency);
    }

    protected function obligation(Wallet $wallet): ?WalletDepositObligation
    {
        /** @var WalletDepositObligation|null $obligation */
        $obligation = WalletDepositObligation::query()
            ->where('wallet_id', $wallet->id)
            ->orderByDesc('id')
            ->first();

        return $obligation;
    }
}
