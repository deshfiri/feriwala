<?php

namespace App\Domain\Tax;

use App\Domain\Tax\Enums\TaxScope;
use App\Domain\Tax\Models\TaxRate;
use App\Domain\Tax\Models\TaxRule;
use Carbon\CarbonImmutable;

/**
 * Finds the rule and the rate that apply to a charge (D19).
 *
 * Separate from {@see TaxEngine} because resolution and arithmetic fail in
 * different ways and are worth being able to test apart: "which rule did you
 * pick and why" is a configuration question, "what is 15% of 5,000" is not.
 *
 * Resolution walks **outward** from the most specific scope. A product rule is
 * asked for first, then the category, then the fee, then the catch-all. The
 * first level that has a match wins outright, so a zero-rated product does not
 * pick up the standard rate its category carries.
 */
class TaxRuleResolver
{
    /**
     * The rule that governs this charge, or null when nothing matches.
     *
     * Null means **no tax**, not "use a default rate". D19 forbids assuming a
     * statutory rate, so an unconfigured system undercharges visibly rather
     * than inventing a number nobody agreed.
     */
    public function resolve(
        TaxScope $scope,
        ?string $scopeValue,
        ?CarbonImmutable $at = null,
    ): ?TaxRule {
        $at ??= CarbonImmutable::now();

        foreach ($this->scopeChain($scope) as $level) {
            $match = $this->bestMatch($level, $scopeValue, $at);

            if ($match !== null) {
                return $match;
            }
        }

        return null;
    }

    /**
     * The rate a code is worth at a given moment.
     *
     * Resolved by date rather than by row id: a rule names a code, and the code
     * resolves to whichever version was in force when the charge was made — not
     * the version in force today. That is what lets an old invoice be
     * recalculated and still agree with itself.
     */
    public function rateFor(string $code, ?CarbonImmutable $at = null): ?TaxRate
    {
        return TaxRate::query()
            ->where('code', $code)
            ->effectiveAt($at ?? CarbonImmutable::now())
            // Latest window wins if two overlap. Overlap is a misconfiguration
            // the admin screen refuses, but a resolver that returned an
            // arbitrary row under one would be worse than one that is at least
            // predictable.
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Scopes to try, most specific first.
     *
     * `Everything` is always last, so a catch-all rule cannot pre-empt a
     * targeted one.
     *
     * @return array<int, TaxScope>
     */
    protected function scopeChain(TaxScope $scope): array
    {
        return $scope === TaxScope::Everything
            ? [TaxScope::Everything]
            : [$scope, TaxScope::Everything];
    }

    /**
     * The winning rule at one level of specificity.
     *
     * Priority breaks ties **here**, inside a single level — never across
     * levels. Comparing priorities between a product rule and a catch-all would
     * let one high-priority global rule silently override every targeted rule
     * beneath it, and nobody would find out until an invoice was wrong.
     */
    protected function bestMatch(TaxScope $scope, ?string $value, CarbonImmutable $at): ?TaxRule
    {
        if ($scope->requiresValue() && $value === null) {
            return null;
        }

        $query = TaxRule::query()
            ->where('scope', $scope->value)
            ->effectiveAt($at)
            ->orderByDesc('priority')
            ->orderByDesc('id');

        if ($scope->requiresValue()) {
            $query->whereRaw('lower(scope_value) = ?', [mb_strtolower((string) $value)]);
        }

        return $query->first();
    }
}
