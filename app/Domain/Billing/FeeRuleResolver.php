<?php

namespace App\Domain\Billing;

use App\Domain\Billing\Enums\FeeType;
use App\Domain\Billing\Models\FeeRule;
use App\Domain\Package\Models\Package;
use App\Domain\Settings\SettingsRepository;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
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
