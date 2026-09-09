<?php

namespace App\Domain\Tax;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\Enums\AllocationType;
use App\Domain\Tax\Data\TaxCharge;
use App\Domain\Tax\Enums\TaxMode;
use App\Domain\Tax\Enums\TaxScope;
use App\Domain\Tax\Models\TaxExemption;
use App\Domain\Tax\Models\TaxRate;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * The single place tax is worked out (D19, §9, §36.1).
 *
 * Everything that charges money asks this — checkout, orders, renewals,
 * invoices — because a second implementation is how an invoice and a ledger
 * entry come to disagree about the same sale by one poisha.
 *
 * Three questions, in order:
 *
 *   1. **Is the account exempt?** If so, nothing else matters. An exemption is a
 *      dated grant with a reason, not a flag.
 *   2. **Which rule applies?** The most specific match wins: product, then
 *      category, then fee, then everything. Priority breaks ties within a level
 *      only, never across them.
 *   3. **What is the rate on this date?** Rules name a rate by code; the code
 *      resolves to whichever version was in force at the time of the charge —
 *      not the version in force today.
 *
 * With no matching rule the answer is **no tax**, deliberately. D19 forbids
 * hardcoding a statutory rate on assumption, so an unconfigured system must
 * undercharge visibly rather than invent 15% and put a number nobody agreed
 * onto real invoices.
 *
 * *Visibly* is the operative word. A rule naming a code whose rate has been
 * withdrawn also charges nothing — and that is a misconfiguration, not a
 * decision, so it is logged rather than passed over in silence.
 */
class TaxEngine
{
    public function __construct(
        protected TaxRuleResolver $rules,
    ) {}

    /**
     * Tax on one amount.
     *
     * `$at` is the moment the charge is *made*, not the moment this runs. A
     * refund or a reissued invoice must reproduce the original arithmetic, and
     * that means resolving against the rate that was in force then.
     */
    public function charge(
        Money $amount,
        ?AllocationType $feeType = null,
        ?BusinessAccount $account = null,
        ?CarbonImmutable $at = null,
        TaxScope $scope = TaxScope::Fee,
        ?string $scopeValue = null,
    ): TaxCharge {
        $at ??= CarbonImmutable::now();

        if ($amount->isZero()) {
            return TaxCharge::none($amount);
        }

        if ($feeType !== null && ! $feeType->isTaxable()) {
            return TaxCharge::none($amount);
        }

        if ($account !== null && $this->isExempt($account, $at)) {
            return TaxCharge::none($amount);
        }

        $scopeValue ??= $feeType?->value;

        $rule = $this->rules->resolve($scope, $scopeValue, $at);

        if ($rule === null) {
            return TaxCharge::none($amount);
        }

        $rate = $this->rules->rateFor($rule->tax_code, $at);

        if ($rate === null) {
            /*
             * The rule matched, but the code it names has no rate in force —
             * withdrawn, not yet started, or never configured. Nothing is
             * charged, because D19 forbids inventing a statutory rate. But this
             * is a misconfiguration rather than a decision, and a rule that
             * quietly taxes nothing looks on the settings screen exactly like a
             * rule that works, so it is said out loud.
             */
            Log::warning('Tax rule matched a code with no rate in force.', [
                'tax_rule' => $rule->public_id,
                'tax_code' => $rule->tax_code,
                'at' => $at->toIso8601String(),
            ]);

            return TaxCharge::none($amount);
        }

        if ($rate->isZeroRated()) {
            return TaxCharge::none($amount);
        }

        return $this->apply($amount, $rate, $rule->mode);
    }

    /**
     * Apply a known rate to a known amount.
     *
     * Split out so a caller holding a rate already — a stored invoice being
     * recalculated, a test asserting the arithmetic — does not have to go back
     * through resolution to get the sums.
     */
    public function apply(Money $amount, TaxRate $rate, TaxMode $mode): TaxCharge
    {
        [$net, $tax] = $mode->isInclusive()
            ? $this->extractInclusive($amount, $rate->rate_basis_points)
            : $this->addExclusive($amount, $rate->rate_basis_points);

        return new TaxCharge(
            code: $rate->code,
            label: $rate->label(),
            rateBasisPoints: $rate->rate_basis_points,
            mode: $mode,
            net: $net,
            tax: $tax,
        );
    }

    /**
     * Whether this account pays no tax right now (D19).
     */
    public function isExempt(BusinessAccount $account, ?CarbonImmutable $at = null): bool
    {
        return TaxExemption::query()
            ->where('business_account_id', $account->id)
            ->effectiveAt($at ?? CarbonImmutable::now())
            ->exists();
    }

    /**
     * Tax added on top. The amount given is net.
     *
     * @return array{Money, Money} net, tax
     */
    protected function addExclusive(Money $net, int $basisPoints): array
    {
        $tax = Money::of(
            (int) round($net->minorUnits * $basisPoints / TaxRate::BASIS_POINTS_WHOLE),
            $net->currency,
        );

        return [$net, $tax];
    }

    /**
     * Tax taken out. The amount given is gross.
     *
     * The net is rounded and the **tax is the remainder**, rather than both
     * being rounded independently. Rounding each separately lets net + tax come
     * to one minor unit more or less than the price the customer was shown — and
     * a gross price that no longer equals its own parts is a reconciliation
     * failure, not a rounding nicety.
     *
     * @return array{Money, Money} net, tax
     */
    protected function extractInclusive(Money $gross, int $basisPoints): array
    {
        $divisor = TaxRate::BASIS_POINTS_WHOLE + $basisPoints;

        $net = Money::of(
            (int) round($gross->minorUnits * TaxRate::BASIS_POINTS_WHOLE / $divisor),
            $gross->currency,
        );

        return [$net, $gross->minus($net)];
    }
}
