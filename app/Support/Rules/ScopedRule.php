<?php

namespace App\Support\Rules;

use DateTimeInterface;

/**
 * A configurable rule that applies within a scope, over a period of time.
 *
 * Implemented by commission rules, deposit rules, withdrawal rules, and referral
 * plans so they can all be resolved by {@see RuleResolver}.
 */
interface ScopedRule
{
    public function ruleScope(): RuleScope;

    /**
     * The id of the record this rule is attached to, or null for a global rule.
     */
    public function ruleScopeId(): int|string|null;

    /**
     * Manual tie-break between rules of the same scope. Higher wins.
     */
    public function rulePriority(): int;

    public function ruleEffectiveFrom(): ?DateTimeInterface;

    public function ruleEffectiveUntil(): ?DateTimeInterface;

    public function ruleIsActive(): bool;

    /**
     * Stable identifier, used as the final deterministic tie-break.
     */
    public function ruleIdentifier(): int|string;
}
