<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\Data\ActivationQuote;
use App\Domain\Billing\Data\QuoteLine;
use App\Domain\Billing\Enums\AllocationType;
use App\Domain\Package\Data\PackageChangePlan;
use App\Domain\Tax\Data\TaxBreakdown;
use App\Domain\Tax\TaxEngine;
use Carbon\CarbonImmutable;

/**
 * What moving to another package costs (§8.3).
 *
 * The plan has already decided the figures; this turns them into the itemised
 * lines that survive into the payment's allocations and its invoice. Every part
 * stays its own line — the new package's fee, the credit for what is unused,
 * the extra deposit, the tax — because §9 requires them stored separately and
 * because a single net figure cannot be explained to anybody afterwards.
 *
 * The credit is a **discount line**, so it reduces the taxable base before tax
 * is charged. Taxing the full fee and then deducting the credit would charge
 * VAT on days the account is not buying.
 *
 * The extra deposit is added last and untaxed: it is the partner's own money
 * moving onto their wallet, not a fee (§9).
 */
class CalculatePackageChangeQuote
{
    public function __construct(
        protected TaxEngine $tax,
    ) {}

    public function handle(
        PackageChangePlan $plan,
        ?BusinessAccount $account = null,
        ?CarbonImmutable $at = null,
    ): ActivationQuote {
        $at ??= CarbonImmutable::now();
        $currency = $plan->grossFee->currency;

        $lines = [];

        if ($plan->grossFee->isPositive()) {
            $lines[] = new QuoteLine(
                $plan->isUpgrade() ? AllocationType::PackageFee : AllocationType::RenewalFee,
                $plan->grossFee,
                __('package.change.line', ['package' => $plan->terms->name]),
            );
        }

        if ($plan->credit->isPositive()) {
            $lines[] = new QuoteLine(
                AllocationType::Discount,
                $plan->credit,
                __('package.change.credit'),
            );
        }

        // Tax on what is actually being charged for the package, after credit.
        $charge = $this->tax->charge(
            amount: $plan->payable(),
            feeType: $plan->isUpgrade() ? AllocationType::PackageFee : AllocationType::RenewalFee,
            account: $account,
            at: $at,
        );

        $breakdown = TaxBreakdown::of($charge->isZero() ? [] : [$charge], $currency);
        $addedTax = $breakdown->addedTotal();

        if ($addedTax->isPositive()) {
            $lines[] = new QuoteLine(AllocationType::Tax, $addedTax, $charge->label);
        }

        // §8.3's additional deposit requirement — only the difference, and only
        // ever upward. It is the partner's money, so it carries no tax.
        if ($plan->additionalDeposit->isPositive()) {
            $lines[] = new QuoteLine(
                AllocationType::WalletDeposit,
                $plan->additionalDeposit,
                __('package.change.deposit'),
            );
        }

        return new ActivationQuote($lines, $currency, $breakdown);
    }
}
