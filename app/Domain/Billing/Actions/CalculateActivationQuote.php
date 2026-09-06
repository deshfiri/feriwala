<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\Data\ActivationQuote;
use App\Domain\Billing\Data\QuoteLine;
use App\Domain\Billing\Enums\AllocationType;
use App\Domain\Package\Models\Package;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Tax\Data\TaxBreakdown;
use App\Domain\Tax\Data\TaxCharge;
use App\Domain\Tax\TaxEngine;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * Works out what an activation costs (§9).
 *
 * Total Activation Payment = Registration Fee + Selected Package Fee, plus any
 * deposit, discount, tax, and gateway charge — each held as its own line so it
 * survives into the payment's allocations, the invoice, and the ledger (§5.1).
 *
 * Order of operations matters and is fixed here rather than left to callers:
 *
 *   1. fees are gathered
 *   2. discount comes off the fees, apportioned across them
 *   3. tax is charged per fee on what remains, at that fee's own rate (D19)
 *   4. the wallet deposit is added, untaxed — it is the partner's own money
 *   5. the gateway charge is applied last, to the amount actually transacted
 *
 * Taxing before discount would overcharge; taxing the deposit would charge VAT
 * on someone's savings.
 *
 * The discount is **apportioned** rather than deducted from a single pooled
 * total, because two fees can carry different rates (D19). Taking 500 off a
 * pool and taxing the remainder at one rate would be arithmetic that no longer
 * belongs to either fee, and an invoice cannot show it per rate.
 */
class CalculateActivationQuote
{
    public function __construct(
        protected SettingsRepository $settings,
        protected TaxEngine $tax,
    ) {}

    public function handle(
        Package $package,
        ?Money $walletDeposit = null,
        ?Money $discount = null,
        ?string $discountDescription = null,
        ?BusinessAccount $account = null,
        ?CarbonImmutable $at = null,
    ): ActivationQuote {
        $currency = $package->fee_minor->currency;
        $at ??= CarbonImmutable::now();

        $lines = [];

        // 1. Fees. A package-specific registration fee overrides the global
        //    one; null means "use the global fee" (§9).
        $registrationFee = $package->registration_fee_minor
            ?? $this->globalRegistrationFee($currency);

        if ($registrationFee->isPositive()) {
            $lines[] = new QuoteLine(AllocationType::RegistrationFee, $registrationFee);
        }

        if ($package->fee_minor->isPositive()) {
            $lines[] = new QuoteLine(
                AllocationType::PackageFee,
                $package->fee_minor,
                $package->name.' package',
            );
        }

        // 2. Discount, capped at the fees so a generous coupon can never make
        //    the total negative and turn a sale into a payout.
        $taxableAmounts = $this->taxableAmounts($lines, $currency);
        $taxableBase = $this->sum($taxableAmounts, $currency);

        if ($discount !== null && $discount->isPositive()) {
            $applied = $discount->greaterThan($taxableBase) ? $taxableBase : $discount;

            $lines[] = new QuoteLine(AllocationType::Discount, $applied, $discountDescription);
            $taxableAmounts = $this->afterDiscount($taxableAmounts, $applied, $currency);
        }

        // 3. Tax, per fee, at that fee's own rate and mode (D19).
        $breakdown = $this->taxOn($taxableAmounts, $account, $at, $currency);

        // Only tax that is *added* joins the total. Inclusive tax is already
        // inside the fee lines above; adding it here would charge it twice.
        $addedTax = $breakdown->addedTotal();

        if ($addedTax->isPositive()) {
            $lines[] = new QuoteLine(
                AllocationType::Tax,
                $addedTax,
                $this->taxLabel($breakdown),
            );
        }

        // 4. Deposit — the partner's money, moved onto their wallet, untaxed.
        if ($walletDeposit !== null && $walletDeposit->isPositive()) {
            $lines[] = new QuoteLine(AllocationType::WalletDeposit, $walletDeposit);
        }

        $quote = new ActivationQuote($lines, $currency, $breakdown);

        // 5. Gateway charge on what is actually being transacted, where the
        //    administrator has chosen to pass it on (§9).
        $gatewayRate = (float) $this->settings->get('billing.gateway_charge_percent', '0');

        if ($gatewayRate > 0 && $quote->total()->isPositive()) {
            $lines[] = new QuoteLine(
                AllocationType::GatewayCharge,
                $quote->total()->percentage($gatewayRate),
            );

            $quote = new ActivationQuote($lines, $currency, $breakdown);
        }

        return $quote;
    }

    /**
     * The registration fee that applies when a package does not set its own.
     */
    protected function globalRegistrationFee(Currency $currency): Money
    {
        $configured = $this->settings->get('billing.registration_fee');

        if ($configured instanceof Money) {
            return $configured;
        }

        return Money::of((int) ($configured ?? 0), $currency);
    }

    /**
     * The taxable fee lines, keyed by allocation type.
     *
     * @param  array<int, QuoteLine>  $lines
     * @return array<string, Money>
     */
    protected function taxableAmounts(array $lines, Currency $currency): array
    {
        $amounts = [];

        foreach ($lines as $line) {
            if (! $line->type->isTaxable()) {
                continue;
            }

            $key = $line->type->value;

            $amounts[$key] = isset($amounts[$key])
                ? $amounts[$key]->plus($line->amount)
                : $line->amount;
        }

        return $amounts;
    }

    /**
     * Spread the discount across the taxable fees in proportion to their size.
     *
     * {@see Money::allocate()} hands out remainder units largest-first, so the
     * shares always sum back to the discount exactly. Splitting by a percentage
     * and rounding each share would lose or invent a poisha, and the tax
     * charged would then not match the discount given.
     *
     * @param  array<string, Money>  $amounts
     * @return array<string, Money>
     */
    protected function afterDiscount(array $amounts, Money $discount, Currency $currency): array
    {
        if ($amounts === []) {
            return $amounts;
        }

        $ratios = array_map(fn (Money $amount) => $amount->minorUnits, $amounts);

        if (array_sum($ratios) === 0) {
            return $amounts;
        }

        $shares = $discount->allocate($ratios);

        $net = [];

        foreach ($amounts as $key => $amount) {
            $net[$key] = $amount->minus($shares[$key]);
        }

        return $net;
    }

    /**
     * @param  array<string, Money>  $amounts
     */
    protected function taxOn(
        array $amounts,
        ?BusinessAccount $account,
        CarbonImmutable $at,
        Currency $currency,
    ): TaxBreakdown {
        $charges = [];

        foreach ($amounts as $key => $amount) {
            $type = AllocationType::from($key);

            $charge = $this->tax->charge(
                amount: $amount,
                feeType: $type,
                account: $account,
                at: $at,
            );

            if (! $charge->isZero()) {
                $charges[] = $charge;
            }
        }

        return TaxBreakdown::of($charges, $currency);
    }

    /**
     * "VAT (15%)" for one rate; a plain "Tax" when several are in play, because
     * naming one of them on a combined line would be wrong about the others.
     */
    protected function taxLabel(TaxBreakdown $breakdown): ?string
    {
        $added = array_values(array_filter(
            $breakdown->charges,
            fn (TaxCharge $charge) => ! $charge->mode->isInclusive(),
        ));

        return count($added) === 1 ? $added[0]->label : null;
    }

    /**
     * @param  array<string, Money>  $amounts
     */
    protected function sum(array $amounts, Currency $currency): Money
    {
        $total = Money::zero($currency);

        foreach ($amounts as $amount) {
            $total = $total->plus($amount);
        }

        return $total;
    }
}
