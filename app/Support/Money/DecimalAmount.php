<?php

namespace App\Support\Money;

use App\Support\Money\Exceptions\InvalidDecimalAmount;

/**
 * The one boundary a human-entered Taka amount crosses on its way to a
 * {@see Money} value (§36.1, §37).
 *
 * A person types "500.25"; the browser sends that string unchanged; this
 * turns it into exactly 50025 minor units, or refuses it outright — never a
 * float, never a silent round of a digit the person actually typed. This is
 * stricter than {@see Money::fromDecimal()}, which several internal callers
 * already rely on to *round* a computed fraction to the currency's scale.
 * A person is not computing a fraction; a third decimal place they typed by
 * hand is almost always a mistake, so this refuses it rather than rounding
 * it away where they cannot see that happened.
 *
 * The only entry point human-facing controllers should use for turning
 * request input into money. Machine-facing endpoints (the frozen storefront
 * API, webhooks) are unaffected — they already send integer minor units
 * with a currency code, which is the correct machine contract and stays
 * exactly as it is.
 */
final class DecimalAmount
{
    /**
     * A sanity ceiling, not a technical one — PHP's own integer range is far
     * larger. Ten billion Taka is well beyond any legitimate single amount
     * this platform moves, and catches a mistyped or adversarial value
     * before it reaches arithmetic that would otherwise silently accept it.
     */
    public const MAX_MAJOR_UNITS = 10_000_000_000;

    private function __construct() {}

    /**
     * Parse a human-entered decimal string into an exact {@see Money}.
     *
     * @throws InvalidDecimalAmount
     */
    public static function parse(string $input, ?Currency $currency = null, bool $allowNegative = false): Money
    {
        $currency ??= Currency::base();
        $trimmed = trim($input);

        if ($trimmed === '') {
            throw InvalidDecimalAmount::malformed($input);
        }

        // Digits and at most one decimal point, nothing else — no thousands
        // separator, no scientific notation, no whitespace inside the number,
        // no unicode look-alike digits. Money::fromDecimal()'s own shape
        // check is this same pattern; kept here too so every failure mode is
        // refused at this boundary rather than reaching it.
        if (! preg_match('/^-?\d+(\.\d+)?$/', $trimmed)) {
            throw InvalidDecimalAmount::malformed($input);
        }

        $negative = str_starts_with($trimmed, '-');

        if ($negative && ! $allowNegative) {
            throw InvalidDecimalAmount::negativeNotAllowed($input);
        }

        $scale = $currency->scale();
        $unsigned = ltrim($trimmed, '-');
        $fraction = str_contains($unsigned, '.') ? explode('.', $unsigned, 2)[1] : '';

        // Rounding is Money::fromDecimal()'s job for a computed fraction.
        // A human typed this digit by hand; a third decimal place for a
        // currency with two is refused, not quietly rounded into a
        // different amount than the one they entered.
        if (mb_strlen($fraction) > $scale) {
            throw InvalidDecimalAmount::excessivePrecision($input, $scale);
        }

        if ((float) $unsigned > self::MAX_MAJOR_UNITS) {
            throw InvalidDecimalAmount::overflow($input);
        }

        return Money::fromDecimal($trimmed, $currency);
    }

    /**
     * {@see parse()}, or null for a blank optional field.
     *
     * @throws InvalidDecimalAmount
     */
    public static function parseOrNull(?string $input, ?Currency $currency = null, bool $allowNegative = false): ?Money
    {
        if ($input === null || trim($input) === '') {
            return null;
        }

        return self::parse($input, $currency, $allowNegative);
    }
}
