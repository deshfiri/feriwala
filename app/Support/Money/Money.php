<?php

namespace App\Support\Money;

use App\Support\Money\Exceptions\CurrencyMismatch;
use InvalidArgumentException;
use JsonSerializable;
use RoundingMode;
use Stringable;

/**
 * An immutable monetary amount, held as an exact decimal string in major units.
 *
 * Feriwala has no poisha or minor-unit convention at any layer (decision D26):
 * `100` means BDT 100 in a form, a column, an API payload, a calculation and a
 * display alike, and nothing multiplies or divides by 100 to cross a boundary.
 * This object holds `"100.00"`, and the `NUMERIC(19,2)` column behind it holds
 * `100.00`.
 *
 * Every monetary value in Feriwala flows through here, and none of it touches a
 * float. Arithmetic is bcmath throughout — `float`, `double`, `round()` and
 * `number_format()` cannot represent 0.10 exactly, and a ledger that must
 * reconcile to the last decimal place cannot tolerate that drift.
 *
 * All instances are immutable: arithmetic returns a new instance rather than
 * mutating the receiver, so a Money handed to another object can never be
 * changed underneath it.
 */
final class Money implements JsonSerializable, Stringable
{
    /**
     * Working precision for intermediate results, before the answer is rounded
     * back to the currency's own scale. Wide enough that a percentage of a
     * percentage cannot lose a place that would have mattered at two.
     */
    private const CALCULATION_SCALE = 12;

    /**
     * Digits available to the left of the decimal point, matching the
     * `NUMERIC(19,2)` money columns. An amount that would not fit its column is
     * refused here rather than at the database, where it would surface as a
     * failed write halfway through a financial transaction.
     */
    private const MAX_INTEGER_DIGITS = 17;

    /**
     * @param  numeric-string  $amount  exact decimal major units, normalized to the currency's scale
     */
    private function __construct(
        public readonly string $amount,
        public readonly Currency $currency,
    ) {}

    /**
     * Create an amount from a decimal string such as "1234.56", or a whole
     * number of major units such as 100 (which means 100 Taka, not 100 poisha).
     *
     * Excess precision is rounded to the currency's scale, because internal
     * callers use this to land a computed fraction. Input a *person* typed goes
     * through {@see DecimalAmount::parse()} instead, which refuses a third
     * decimal place rather than quietly rounding it into a different amount.
     *
     * A float is not accepted. By the time a value is a float the damage is
     * already done, and accepting one here would let it in silently.
     */
    public static function fromDecimal(string|int $amount, ?Currency $currency = null, RoundingMode $rounding = RoundingMode::HalfAwayFromZero): self
    {
        $currency ??= Currency::base();

        $normalized = trim((string) $amount);

        if (! preg_match('/^-?\d+(\.\d+)?$/', $normalized)) {
            throw new InvalidArgumentException("[{$normalized}] is not a valid decimal amount.");
        }

        assert(is_numeric($normalized));

        $rounded = bcround($normalized, $currency->scale(), $rounding);

        self::assertFits($rounded, $normalized);

        return new self(self::normalize($rounded, $currency), $currency);
    }

    /**
     * Create a zero amount.
     */
    public static function zero(?Currency $currency = null): self
    {
        $currency ??= Currency::base();

        return new self(self::normalize('0', $currency), $currency);
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return $this->withAmount(bcadd($this->amount, $other->amount, $this->currency->scale()));
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return $this->withAmount(bcsub($this->amount, $other->amount, $this->currency->scale()));
    }

    /**
     * Multiply by an exact scalar, rounding the result to the currency's scale.
     *
     * The factor is a decimal string or an integer — never a float, which could
     * not carry an exact 0.075 to begin with.
     */
    public function multipliedBy(string|int $factor, RoundingMode $rounding = RoundingMode::HalfAwayFromZero): self
    {
        $product = bcmul($this->amount, self::scalar($factor), self::CALCULATION_SCALE);

        return $this->withAmount(bcround($product, $this->currency->scale(), $rounding));
    }

    /**
     * Take a percentage of this amount — the basis of percentage commission,
     * tax, and gateway charge calculations.
     *
     * The rounding mode is part of the calculation, not an afterthought: a
     * commission rounded one way and a reversal rounded the other will not
     * reconcile, so callers that care state it explicitly.
     */
    public function percentage(string|int $percent, RoundingMode $rounding = RoundingMode::HalfAwayFromZero): self
    {
        $rate = bcdiv(self::scalar($percent), '100', self::CALCULATION_SCALE);
        $product = bcmul($this->amount, $rate, self::CALCULATION_SCALE);

        return $this->withAmount(bcround($product, $this->currency->scale(), $rounding));
    }

    public function negated(): self
    {
        return $this->withAmount(bcsub('0', $this->amount, $this->currency->scale()));
    }

    public function absolute(): self
    {
        return $this->isNegative() ? $this->negated() : $this;
    }

    /**
     * Split this amount across the given integer ratios without losing or
     * inventing a single decimal place.
     *
     * Each share is truncated toward zero, then the remainder is handed out one
     * smallest-unit at a time to the largest ratios first — so allocating 1.00
     * across [1, 1, 1] yields [0.34, 0.33, 0.33], never [0.33, 0.33, 0.33] with
     * a paisa unaccounted for. The shares always sum back to exactly this
     * amount, which is what makes it safe for prorating a discount, splitting
     * tax across lines, or apportioning a refund.
     *
     * @param  array<int|string, int>  $ratios
     * @return array<int|string, self>
     */
    public function allocate(array $ratios): array
    {
        if ($ratios === []) {
            throw new InvalidArgumentException('Cannot allocate money across an empty set of ratios.');
        }

        $total = array_sum($ratios);

        if ($total <= 0) {
            throw new InvalidArgumentException('Allocation ratios must sum to a positive number.');
        }

        $scale = $this->currency->scale();
        $shares = [];
        $allocated = '0';

        foreach ($ratios as $key => $ratio) {
            // bcdiv truncates toward zero at the given scale, so no share is
            // ever rounded up past what the ratio earns it.
            $share = bcdiv(bcmul($this->amount, (string) $ratio, self::CALCULATION_SCALE), (string) $total, $scale);
            $shares[$key] = $share;
            $allocated = bcadd($allocated, $share, $scale);
        }

        $remainder = bcsub($this->amount, $allocated, $scale);

        // Distribute the remainder to the largest ratios first, deterministically.
        $order = array_keys($ratios);
        usort($order, fn ($a, $b) => $ratios[$b] <=> $ratios[$a]);

        $step = bccomp($remainder, '0', $scale) >= 0
            ? $this->currency->smallestUnit()
            : '-'.$this->currency->smallestUnit();

        assert(is_numeric($step));

        $index = 0;

        while (bccomp($remainder, '0', $scale) !== 0) {
            $key = $order[$index % count($order)];
            $shares[$key] = bcadd($shares[$key], $step, $scale);
            $remainder = bcsub($remainder, $step, $scale);
            $index++;
        }

        return array_map(fn (string $share) => $this->withAmount($share), $shares);
    }

    public function isZero(): bool
    {
        return $this->compareTo($this->amount, '0') === 0;
    }

    public function isPositive(): bool
    {
        return $this->compareTo($this->amount, '0') > 0;
    }

    public function isNegative(): bool
    {
        return $this->compareTo($this->amount, '0') < 0;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency
            && $this->compareTo($this->amount, $other->amount) === 0;
    }

    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->compareTo($this->amount, $other->amount) > 0;
    }

    public function greaterThanOrEqualTo(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->compareTo($this->amount, $other->amount) >= 0;
    }

    public function lessThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->compareTo($this->amount, $other->amount) < 0;
    }

    public function lessThanOrEqualTo(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->compareTo($this->amount, $other->amount) <= 0;
    }

    /**
     * The amount as an exact decimal string, e.g. "1234.56".
     *
     * This is what the database column stores and what the wire carries — the
     * same digits at every layer, with no conversion between them.
     *
     * @return numeric-string
     */
    public function toDecimal(): string
    {
        return $this->amount;
    }

    /**
     * A human-readable amount with thousands separators and currency symbol.
     *
     * Grouping is done on the digits themselves. Routing through
     * `number_format()` would mean routing through a float, and an amount large
     * enough to lose precision there is exactly the amount worth getting right.
     */
    public function format(bool $withSymbol = true): string
    {
        $negative = str_starts_with($this->amount, '-');
        $unsigned = ltrim($this->amount, '-');

        [$whole, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');

        $grouped = strrev(implode(',', str_split(strrev($whole), 3)));

        $formatted = ($negative ? '-' : '').$grouped.($fraction === '' ? '' : '.'.$fraction);

        return $withSymbol
            ? $this->currency->symbol().$formatted
            : $formatted;
    }

    /**
     * Shape sent to the front end and over the wire, so nothing downstream ever
     * does arithmetic on money.
     *
     * `amount` is flat Taka — `"100.50"` means BDT 100.50. The frozen storefront
     * API's legacy `minor_units` key is added by its own compatibility adapter
     * at that one boundary, never here.
     *
     * @return array{amount: string, currency: string, formatted: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'amount' => $this->amount,
            'currency' => $this->currency->value,
            'formatted' => $this->format(),
        ];
    }

    public function __toString(): string
    {
        return $this->format();
    }

    /**
     * @param  numeric-string  $amount
     */
    private function withAmount(string $amount): self
    {
        self::assertFits($amount, $amount);

        return new self(self::normalize($amount, $this->currency), $this->currency);
    }

    /**
     * Force the value to the currency's scale and collapse "-0.00" to "0.00",
     * so two equal amounts are always the same string.
     *
     * @param  numeric-string  $amount
     * @return numeric-string
     */
    private static function normalize(string $amount, Currency $currency): string
    {
        return bcadd($amount, '0', $currency->scale());
    }

    /**
     * @param  numeric-string  $left
     * @param  numeric-string  $right
     */
    private function compareTo(string $left, string $right): int
    {
        return bccomp($left, $right, $this->currency->scale());
    }

    /**
     * Validate a multiplier or percentage — exact decimals only, no float.
     *
     * @return numeric-string
     */
    private static function scalar(string|int $value): string
    {
        $scalar = trim((string) $value);

        if (! preg_match('/^-?\d+(\.\d+)?$/', $scalar)) {
            throw new InvalidArgumentException("[{$scalar}] is not a valid exact decimal factor.");
        }

        assert(is_numeric($scalar));

        return $scalar;
    }

    private static function assertFits(string $amount, string $original): void
    {
        $digits = strlen(explode('.', ltrim($amount, '-'), 2)[0]);

        if ($digits > self::MAX_INTEGER_DIGITS) {
            throw new InvalidArgumentException(sprintf(
                '[%s] exceeds the %d integer digits a money column holds.',
                $original,
                self::MAX_INTEGER_DIGITS,
            ));
        }
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw CurrencyMismatch::between($this->currency, $other->currency);
        }
    }
}
