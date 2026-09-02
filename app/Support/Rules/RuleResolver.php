<?php

namespace App\Support\Rules;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Decides which configurable rule wins for a given context and moment.
 *
 * Used by commission (§22.1), deposit and minimum balance (§24.1), withdrawal
 * (§27.2–§27.3), and referral plans (§25.4). One resolver, so precedence behaves
 * identically everywhere and can be reasoned about — and tested — in one place.
 *
 * Resolution order:
 *
 *   1. discard rules that are inactive, out of their effective window, or scoped
 *      to something this context is not
 *   2. most specific scope wins        (user beats package beats global)
 *   3. then highest explicit priority
 *   4. then the most recently effective rule
 *   5. then the highest identifier, purely so the outcome is deterministic
 *
 * Step 5 matters more than it looks: without a final tie-break, two equally
 * ranked rules would resolve according to database ordering, and the same
 * commission could be calculated differently on two servers.
 */
class RuleResolver
{
    /**
     * The single winning rule, or null when nothing applies.
     *
     * @template TRule of ScopedRule
     *
     * @param  iterable<TRule>  $rules
     * @return TRule|null
     */
    public function resolve(iterable $rules, RuleContext $context, ?DateTimeInterface $at = null): ?ScopedRule
    {
        return $this->applicable($rules, $context, $at)[0] ?? null;
    }

    /**
     * Every applicable rule, best first.
     *
     * Useful for showing an administrator why a rule won, and what it beat.
     *
     * @template TRule of ScopedRule
     *
     * @param  iterable<TRule>  $rules
     * @return array<int, TRule>
     */
    public function applicable(iterable $rules, RuleContext $context, ?DateTimeInterface $at = null): array
    {
        $at ??= new DateTimeImmutable;

        $applicable = [];

        foreach ($rules as $rule) {
            if ($this->applies($rule, $context, $at)) {
                $applicable[] = $rule;
            }
        }

        usort($applicable, fn (ScopedRule $a, ScopedRule $b) => $this->compare($a, $b));

        return $applicable;
    }

    /**
     * Whether a single rule is in play for this context at this moment.
     */
    public function applies(ScopedRule $rule, RuleContext $context, ?DateTimeInterface $at = null): bool
    {
        $at ??= new DateTimeImmutable;

        if (! $rule->ruleIsActive()) {
            return false;
        }

        if (! $this->withinWindow($rule, $at)) {
            return false;
        }

        return $context->matches($rule->ruleScope(), $rule->ruleScopeId());
    }

    /**
     * Effective window check.
     *
     * `from` is inclusive and `until` is exclusive, so two rules can hand over at
     * the same instant without a gap where neither applies or an overlap where
     * both do.
     */
    protected function withinWindow(ScopedRule $rule, DateTimeInterface $at): bool
    {
        $from = $rule->ruleEffectiveFrom();

        if ($from !== null && $at < $from) {
            return false;
        }

        $until = $rule->ruleEffectiveUntil();

        return $until === null || $at < $until;
    }

    /**
     * Order two applicable rules, best first.
     */
    protected function compare(ScopedRule $a, ScopedRule $b): int
    {
        return $b->ruleScope()->specificity() <=> $a->ruleScope()->specificity()
            ?: $b->rulePriority() <=> $a->rulePriority()
            ?: $this->compareEffectiveFrom($a, $b)
            ?: (string) $b->ruleIdentifier() <=> (string) $a->ruleIdentifier();
    }

    /**
     * More recently effective wins. A rule with no start date is treated as
     * having always applied, so it loses to one that started deliberately.
     */
    protected function compareEffectiveFrom(ScopedRule $a, ScopedRule $b): int
    {
        $aFrom = $a->ruleEffectiveFrom();
        $bFrom = $b->ruleEffectiveFrom();

        if ($aFrom === $bFrom) {
            return 0;
        }

        if ($aFrom === null) {
            return 1;
        }

        if ($bFrom === null) {
            return -1;
        }

        return $bFrom <=> $aFrom;
    }
}
