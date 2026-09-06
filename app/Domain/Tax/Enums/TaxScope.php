<?php

namespace App\Domain\Tax\Enums;

/**
 * What a tax rule applies to (D19).
 *
 * Ordered from general to specific, and that order is the whole resolution
 * strategy: when several rules match, the most specific one wins. A product
 * rule beats a category rule, which beats a fee rule, which beats the default —
 * so a zero-rated book does not pick up the standard rate its category carries.
 *
 * Priority breaks ties **within** a level of specificity, not across them. That
 * matters: without it, an administrator setting a high priority on a broad rule
 * would silently override every specific rule beneath it, and nobody would find
 * out until an invoice was wrong.
 */
enum TaxScope: string
{
    /** Applies to everything with no more specific rule. */
    case Everything = 'everything';

    /** One charge component — a registration fee, a package fee (§9). */
    case Fee = 'fee';

    /** A product category. */
    case Category = 'category';

    /** One product. */
    case Product = 'product';

    public function label(): string
    {
        return match ($this) {
            self::Everything => 'Everything',
            self::Fee => 'Specific charge',
            self::Category => 'Product category',
            self::Product => 'Single product',
        };
    }

    /**
     * How specific this scope is. Higher wins.
     */
    public function specificity(): int
    {
        return match ($this) {
            self::Everything => 0,
            self::Fee => 1,
            self::Category => 2,
            self::Product => 3,
        };
    }

    /**
     * Whether a rule in this scope must name what it applies to.
     *
     * `Everything` must not: a default rule with a value would look targeted
     * while behaving globally.
     */
    public function requiresValue(): bool
    {
        return $this !== self::Everything;
    }
}
