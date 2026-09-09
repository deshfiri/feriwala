<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\Data\ActivationQuote;
use App\Domain\Billing\Data\QuoteLine;
use App\Domain\Billing\Enums\AllocationType;
use App\Domain\Package\Data\SubscriptionTerms;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Tax\Data\TaxBreakdown;
use App\Domain\Tax\TaxEngine;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * What renewing a term costs (§8.2).
 *
 * Shorter than an activation quote and deliberately so. A renewal carries **no
 * registration fee** — that is charged once, when the account is created (§9) —
 * and no wallet deposit, which was taken at activation and is still the
 * partner's money sitting on their wallet.
 *
 * The fee comes from the terms the renewal is being taken **on**, captured
 * fresh at the moment of renewal rather than copied from the term running out.
 * That is what §8.3 means by a change of terms being something an account
 * agrees to: last year's price does not bind Feriwala forever, and this year's
 * is shown in full before anything is paid.
 *
 * Where a package sets no renewal fee at all, renewing costs what the package
 * costs. §8.1 lists the renewal fee as its own optional field, and silence
 * there means "no separate renewal price" rather than "free".
 *
 * Recurring service charges — website maintenance, domain, hosting — are not
 * added here. They have their own charge rows, their own recurrence, and their
 * own owning tasks in the website module; folding them into a package renewal
 * would bill them on the package's cycle instead of their own.
 */
class CalculateRenewalQuote
{
    public function __construct(
        protected SettingsRepository $settings,
        protected TaxEngine $tax,
    ) {}

    public function handle(
        SubscriptionTerms $terms,
        ?BusinessAccount $account = null,
        ?CarbonImmutable $at = null,
    ): ActivationQuote {
        $currency = Currency::from($terms->currencyCode);
        $at ??= CarbonImmutable::now();

        $fee = Money::of($terms->renewalFeeMinor ?? $terms->feeMinor, $currency);

        if (! $fee->isPositive()) {
            /*
             * A genuinely free renewal — a promotional term carried on, say.
             * It still produces a quote so the screen can say "nothing to pay"
             * rather than fail to render, and `isPayable()` keeps it away from
             * a gateway.
             */
            return new ActivationQuote([], $currency, TaxBreakdown::empty($currency));
        }

        $lines = [new QuoteLine(
            AllocationType::RenewalFee,
            $fee,
            __('package.renewal.line', ['package' => $terms->name]),
        )];

        $charge = $this->tax->charge(
            amount: $fee,
            feeType: AllocationType::RenewalFee,
            account: $account,
            at: $at,
        );

        $breakdown = TaxBreakdown::of($charge->isZero() ? [] : [$charge], $currency);

        // Only tax that is *added* joins the total. Inclusive tax is already
        // inside the fee above, and adding it here would charge it twice.
        $addedTax = $breakdown->addedTotal();

        if ($addedTax->isPositive()) {
            $lines[] = new QuoteLine(AllocationType::Tax, $addedTax, $charge->label);
        }

        $quote = new ActivationQuote($lines, $currency, $breakdown);

        // The gateway charge, where an administrator has chosen to pass it on
        // (§9). Same setting and same position as the activation quote — last,
        // on the amount actually being transacted.
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
}
