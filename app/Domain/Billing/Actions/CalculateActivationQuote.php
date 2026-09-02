<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Data\ActivationQuote;
use App\Domain\Billing\Data\QuoteLine;
use App\Domain\Billing\Enums\AllocationType;
use App\Domain\Package\Models\Package;
use App\Domain\Settings\SettingsRepository;
use App\Support\Money\Currency;
use App\Support\Money\Money;

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
 *   2. discount comes off the fees
 *   3. tax is charged on what remains, and only on taxable components
 *   4. the wallet deposit is added, untaxed — it is the partner's own money
 *   5. the gateway charge is applied last, to the amount actually transacted
 *
 * Taxing before discount would overcharge; taxing the deposit would charge VAT
 * on someone's savings.
 */
class CalculateActivationQuote
{
    public function __construct(
        protected SettingsRepository $settings,
    ) {}

    public function handle(
        Package $package,
        ?Money $walletDeposit = null,
        ?Money $discount = null,
        ?string $discountDescription = null,
    ): ActivationQuote {
        $currency = $package->fee_minor->currency;

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
        $taxableBase = $this->taxableTotal($lines, $currency);

        if ($discount !== null && $discount->isPositive()) {
            $applied = $discount->greaterThan($taxableBase) ? $taxableBase : $discount;

            $lines[] = new QuoteLine(AllocationType::Discount, $applied, $discountDescription);
            $taxableBase = $taxableBase->minus($applied);
        }

        // 3. Tax on the discounted, taxable amount.
        $taxRate = (float) $this->settings->get('billing.tax_rate_percent', '0');

        if ($taxRate > 0 && $taxableBase->isPositive()) {
            $lines[] = new QuoteLine(
                AllocationType::Tax,
                $taxableBase->percentage($taxRate),
                sprintf('VAT (%s%%)', rtrim(rtrim(number_format($taxRate, 2, '.', ''), '0'), '.')),
            );
        }

        // 4. Deposit — the partner's money, moved onto their wallet, untaxed.
        if ($walletDeposit !== null && $walletDeposit->isPositive()) {
            $lines[] = new QuoteLine(AllocationType::WalletDeposit, $walletDeposit);
        }

        $quote = new ActivationQuote($lines, $currency);

        // 5. Gateway charge on what is actually being transacted, where the
        //    administrator has chosen to pass it on (§9).
        $gatewayRate = (float) $this->settings->get('billing.gateway_charge_percent', '0');

        if ($gatewayRate > 0 && $quote->total()->isPositive()) {
            $lines[] = new QuoteLine(
                AllocationType::GatewayCharge,
                $quote->total()->percentage($gatewayRate),
            );

            $quote = new ActivationQuote($lines, $currency);
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
     * @param  array<int, QuoteLine>  $lines
     */
    protected function taxableTotal(array $lines, Currency $currency): Money
    {
        $total = Money::zero($currency);

        foreach ($lines as $line) {
            if ($line->type->isTaxable()) {
                $total = $total->plus($line->amount);
            }
        }

        return $total;
    }
}
