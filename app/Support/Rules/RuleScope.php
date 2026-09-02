<?php

namespace App\Support\Rules;

/**
 * The scopes a configurable business rule can be attached to.
 *
 * Commission (§22.1), deposit and minimum balance (§24.1), withdrawal (§27.2,
 * §27.3), and referral (§25.4) rules all share this shape, so they share one
 * resolver rather than growing four subtly different precedence bugs.
 */
enum RuleScope: string
{
    case Global = 'global';
    case Package = 'package';
    case Category = 'category';
    case Product = 'product';
    case Website = 'website';
    case User = 'user';
    case Campaign = 'campaign';

    /**
     * How specific this scope is. Higher wins.
     *
     * The ordering encodes a deliberate reading of the spec:
     *
     *  - Global is the fallback everything else overrides.
     *  - Package, then category, then product narrow the subject.
     *  - Website narrows further — it belongs to exactly one account.
     *  - User beats all of the above, because §27.3 states plainly that a valid
     *    user-specific withdrawal rule overrides the corresponding default.
     *  - Campaign sits highest: a running promotion is a deliberate, time-boxed
     *    decision to override standing terms, and it expires on its own.
     *
     * Gaps of 10 leave room to insert a scope later without renumbering.
     */
    public function specificity(): int
    {
        return match ($this) {
            self::Global => 0,
            self::Package => 10,
            self::Category => 20,
            self::Product => 30,
            self::Website => 40,
            self::User => 50,
            self::Campaign => 60,
        };
    }

    /**
     * Whether this scope applies to everyone rather than to a specific record.
     */
    public function isGlobal(): bool
    {
        return $this === self::Global;
    }

    public function label(): string
    {
        return match ($this) {
            self::Global => 'Global default',
            self::Package => 'Package-specific',
            self::Category => 'Category-specific',
            self::Product => 'Product-specific',
            self::Website => 'Website-specific',
            self::User => 'User-specific',
            self::Campaign => 'Campaign',
        };
    }
}
