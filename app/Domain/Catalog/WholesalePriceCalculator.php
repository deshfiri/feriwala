<?php

namespace App\Domain\Catalog;

use App\Support\Money\Money;
use InvalidArgumentException;
use RoundingMode;

/**
 * The one formula behind percentage wholesale pricing, shared by products,
 * variations, the form preview and every write path so they cannot disagree.
 *
 * A markup, not a margin: `base + base × percentage ÷ 100`. The markup amount is
 * rounded half away from zero to the currency's own scale (two places of Taka),
 * and the sum is exact — bcmath throughout, never a float (D26).
 */
final class WholesalePriceCalculator
{
    /**
     * The highest markup accepted. No existing business rule sets another.
     */
    public const MAX_PERCENTAGE = '1000';

    /**
     * Decimal places a percentage may carry; a third is refused, not rounded.
     */
    public const PERCENTAGE_SCALE = 2;

    public function wholesalePrice(Money $baseCost, string $markupPercentage): Money
    {
        $markup = self::normalizePercentage($markupPercentage);

        return $baseCost->plus($baseCost->percentage($markup, RoundingMode::HalfAwayFromZero));
    }

    /**
     * Whether a typed percentage is acceptable: digits with at most two
     * decimals, between 0 and the ceiling. No sign, exponent, infinity or NaN.
     */
    public static function isValidPercentage(mixed $value): bool
    {
        if (! is_string($value) && ! is_int($value)) {
            return false;
        }

        $trimmed = trim((string) $value);

        if (preg_match('/^\d{1,4}(\.\d{1,'.self::PERCENTAGE_SCALE.'})?$/', $trimmed) !== 1) {
            return false;
        }

        assert(is_numeric($trimmed));

        return bccomp($trimmed, self::MAX_PERCENTAGE, self::PERCENTAGE_SCALE) <= 0;
    }

    /**
     * The percentage as the exact two-place decimal that is stored.
     *
     * @return numeric-string
     *
     * @throws InvalidArgumentException
     */
    public static function normalizePercentage(string|int $value): string
    {
        if (! self::isValidPercentage($value)) {
            throw new InvalidArgumentException("[{$value}] is not a valid markup percentage.");
        }

        $trimmed = trim((string) $value);

        assert(is_numeric($trimmed));

        return bcadd($trimmed, '0', self::PERCENTAGE_SCALE);
    }
}
