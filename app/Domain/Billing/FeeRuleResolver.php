<?php

namespace App\Domain\Billing;

use App\Domain\Billing\Enums\FeeType;
use App\Domain\Billing\Models\FeeRule;
use App\Domain\Package\Models\Package;
use App\Domain\Settings\SettingsRepository;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What a fee costs, on a given date (§9).
 *
 * Resolution walks **outward** from the most specific rule, the same shape the
 * tax resolver uses: a rule naming this package wins outright, and only when
 * none exists does the global rule apply. Specificity beats recency, so a
 * newer global price cannot quietly override a deal struck for one plan.
 *
 * `$at` is the moment the charge is being **made**, not the moment this runs.
 * A reissued quote, a recalculated invoice or a refund has to reproduce the
 * arithmetic it did originally, and that means resolving against the rule that
 * was in force then.
 *
 * Where nothing is configured the answer is **zero**, not a guess. An
 * unconfigured platform undercharges visibly rather than inventing a fee
 * nobody agreed — the same reasoning D19 applies to tax.
 */
class FeeRuleResolver
{
    /**
     * The legacy flat setting, read only when no rule exists.
     *
     * Kept so a deployment that configured the fee before rules existed does
     * not start charging nothing on the deploy that adds them. It has no
     * effective dates, which is why it is the last thing asked.
     */
    public const LEGACY_SETTING = 'billing.registration_fee';

    public function __construct(
        protected SettingsRepository $settings,
    ) {}

    /**
     * The registration fee for a package at a moment (§9).
     *
     * The package's own `registration_fee_minor` is still the package-specific
     * override — it is edited where the rest of the package is, and moving it
     * here would mean two screens that can disagree. A package-scoped rule sits
     * above it for the case §9 actually describes: a dated change to one plan's
     * registration fee without touching the plan.
     */
    public function registrationFee(?Package $package, Currency $currency, ?CarbonImmutable $at = null): Money
    {
        $at ??= CarbonImmutable::now();

        if ($package !== null) {
            $scoped = $this->ruleFor(FeeType::Registration, $package->id, $at);

            if ($scoped !== null) {
                return $scoped->amount_minor;
            }

            if ($package->registration_fee_minor !== null) {
                return $package->registration_fee_minor;
            }
        }

        $global = $this->ruleFor(FeeType::Registration, null, $at);

        if ($global !== null) {
            return $global->amount_minor;
        }

        return $this->legacyFee($currency);
    }

    /**
     * The delivery charge on an ERP wholesale checkout (§14, P4-7).
     *
     * A rule for the account's package wins, then the global rule, then zero —
     * there is no legacy setting to fall back on, and no amount is assumed. A rule
     * priced in another currency is not converted: it charges nothing and says so,
     * the way a tax rule naming a withdrawn code does.
     */
    public function wholesaleDelivery(?Package $package, Currency $currency, ?CarbonImmutable $at = null): Money
    {
        $at ??= CarbonImmutable::now();

        $rule = ($package !== null ? $this->ruleFor(FeeType::WholesaleDelivery, $package->id, $at) : null)
            ?? $this->ruleFor(FeeType::WholesaleDelivery, null, $at);

        if ($rule === null) {
            return Money::zero($currency);
        }

        if ($rule->amount_minor->currency !== $currency) {
            Log::warning('Wholesale delivery rule is priced in another currency.', [
                'fee_rule' => $rule->id,
                'rule_currency' => $rule->amount_minor->currency->value,
                'currency' => $currency->value,
            ]);

            return Money::zero($currency);
        }

        return $rule->amount_minor;
    }

    /**
     * What one of the website charges costs for a package, on a date (§16.2, P5-10).
     *
     * The same outward walk as every other fee — the account's package first,
     * then the global rule, then zero. Zero is a real answer: a plan that
     * includes hosting charges nothing for it, and a platform that has priced
     * nothing must under-charge visibly rather than invent a figure.
     *
     * A rule priced in another currency charges nothing and says so, as the
     * wholesale delivery rule does: converting would apply a rate nobody agreed
     * to somebody's money (D4).
     */
    public function websiteCharge(FeeType $type, ?Package $package, Currency $currency, ?CarbonImmutable $at = null): Money
    {
        $at ??= CarbonImmutable::now();

        $rule = ($package !== null ? $this->ruleFor($type, $package->id, $at) : null)
            ?? $this->ruleFor($type, null, $at);

        if ($rule === null) {
            return Money::zero($currency);
        }

        if ($rule->amount_minor->currency !== $currency) {
            Log::warning('Website charge rule is priced in another currency.', [
                'fee_rule' => $rule->id,
                'fee_type' => $type->value,
                'rule_currency' => $rule->amount_minor->currency->value,
                'currency' => $currency->value,
            ]);

            return Money::zero($currency);
        }

        return $rule->amount_minor;
    }

    /**
     * The rule in force for one fee at one level of specificity.
     *
     * The latest window wins where two overlap. Overlap is a misconfiguration
     * the admin screen refuses, but a resolver that returned an arbitrary row
     * under one would be worse than one that is at least predictable.
     */
    public function ruleFor(FeeType $type, ?int $packageId, ?CarbonImmutable $at = null): ?FeeRule
    {
        $query = FeeRule::query()
            ->where('fee_type', $type->value)
            ->effectiveAt($at ?? CarbonImmutable::now())
            ->orderByDesc('effective_from')
            ->orderByDesc('id');

        $packageId === null
            ? $query->whereNull('package_id')
            : $query->where('package_id', $packageId);

        return $query->first();
    }

    protected function legacyFee(Currency $currency): Money
    {
        try {
            $configured = $this->settings->get(self::LEGACY_SETTING);
        } catch (Throwable) {
            return Money::zero($currency);
        }

        if ($configured instanceof Money) {
            return $configured;
        }

        return Money::of((int) ($configured ?? 0), $currency);
    }
}
