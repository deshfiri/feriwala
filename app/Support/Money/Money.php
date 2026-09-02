<?php

namespace App\Support\Money;

use App\Support\Money\Exceptions\CurrencyMismatch;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * An immutable monetary amount, stored as integer minor units.
 *
 * Every monetary value in Feriwala flows through this object. Floating point is
 * never used to hold or accumulate money (requirements.txt §36.1, §37, decision D4):
 * a float cannot represent 0.10 exactly, and a ledger that must reconcile to the
 * poisha cannot tolerate that drift.
 *
 * All instances are immutable — arithmetic returns a new instance rather than
 * mutating the receiver, so a Money handed to another object can never be changed
 * underneath it.
 */
final class Money implements JsonSerializable, Stringable
{
    private function __construct(
        public readonly int $minorUnits,
        public readonly Currency $currency,
    ) {}

    /**
     * Create an amount from integer minor units (poisha for BDT).
     */
    public static function of(int $minorUnits, ?Currency $currency = null): self
    {
        return new self($minorUnits, $currency ?? Currency::base());
    }

    /**
     * Create a zero amount.
     */
    public static function zero(?Currency $currency = null): self
    {
        return new self(0, $currency ?? Currency::base());
    }

    /**
     * Create an amount from a decimal string such as "1234.56".
     *
     * Accepts a string to avoid a float ever holding the value. A float argument is
     * permitted for convenience at boundaries, but is converted through a string
     * with the currency's scale so the caller cannot smuggle in more precision than
     * the currency has.
     */
    public static function fromDecimal(string|int|float $amount, ?Currency $currency = null): self
    {
        $currency ??= Currency::base();

        if (is_float($amount)) {
            $amount = number_format($amount, $currency->scale(), '.', '');
        }

        $amount = trim((string) $amount);

        if (! preg_match('/^-?\d+(\.\d+)?$/', $amount)) {
            throw new InvalidArgumentException("[{$amount}] is not a valid decimal amount.");
        }

        $negative = str_starts_with($amount, '-');
        $amount = ltrim($amount, '-');

        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        $scale = $currency->scale();

        // Round the fraction to the currency's scale rather than truncating, so
        // 1.005 becomes 1.01 instead of silently losing a poisha.
        $fraction = str_pad($fraction, $scale + 1, '0');
        $keep = (int) substr($fraction, 0, $scale);
        $next = (int) $fraction[$scale];

        $minorUnits = ((int) $whole) * $currency->minorUnitFactor() + $keep;

        if ($next >= 5) {
            $minorUnits++;
        }

        return new self($negative ? -$minorUnits : $minorUnits, $currency);
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits + $other->minorUnits, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits - $other->minorUnits, $this->currency);
    }

    /**
     * Multiply by a scalar, rounding half up to the nearest minor unit.
     *
     * @param  1|2|3|4  $roundingMode  one of the PHP_ROUND_HALF_* constants
     */
    public function multipliedBy(int|float|string $factor, int $roundingMode = PHP_ROUND_HALF_UP): self
    {
        $result = (int) round($this->minorUnits * (float) $factor, 0, $roundingMode);

        return new self($result, $this->currency);
    }

    /**
     * Take a percentage of this amount — the basis of percentage commission,
     * tax, and gateway charge calculations.
     *
     * @param  1|2|3|4  $roundingMode  one of the PHP_ROUND_HALF_* constants
     */
    public function percentage(int|float|string $percent, int $roundingMode = PHP_ROUND_HALF_UP): self
    {
        return $this->multipliedBy((float) $percent / 100, $roundingMode);
    }

    public function negated(): self
    {
        return new self(-$this->minorUnits, $this->currency);
    }

    public function absolute(): self
    {
        return new self(abs($this->minorUnits), $this->currency);
    }

    /**
     * Split this amount across the given integer ratios without losing or
     * inventing a single minor unit.
     *
     * Remainder units are handed out one at a time to the largest ratios first,
     * so allocating 100 across [1, 1, 1] yields [34, 33, 33] — never [33, 33, 33]
     * with a poisha unaccounted for. Used for proration and fee splitting.
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

        $shares = [];
        $allocated = 0;

        foreach ($ratios as $key => $ratio) {
            $share = intdiv($this->minorUnits * $ratio, $total);
            $shares[$key] = $share;
            $allocated += $share;
        }

        $remainder = $this->minorUnits - $allocated;

        // Distribute the remainder to the largest ratios first, deterministically.
        $order = array_keys($ratios);
        usort($order, fn ($a, $b) => $ratios[$b] <=> $ratios[$a]);

        $index = 0;
        $step = $remainder >= 0 ? 1 : -1;

        while ($remainder !== 0) {
            $shares[$order[$index % count($order)]] += $step;
            $remainder -= $step;
            $index++;
        }

        return array_map(fn (int $share) => new self($share, $this->currency), $shares);
    }

    public function isZero(): bool
    {
        return $this->minorUnits === 0;
    }

    public function isPositive(): bool
    {
        return $this->minorUnits > 0;
    }

    public function isNegative(): bool
    {
        return $this->minorUnits < 0;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency
            && $this->minorUnits === $other->minorUnits;
    }

    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minorUnits > $other->minorUnits;
    }

    public function greaterThanOrEqualTo(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minorUnits >= $other->minorUnits;
    }

    public function lessThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minorUnits < $other->minorUnits;
    }

    public function lessThanOrEqualTo(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minorUnits <= $other->minorUnits;
    }

    /**
     * The amount as a decimal string, e.g. "1234.56".
     *
     * Returns a string, not a float, so it can be compared and stored without
     * reintroducing binary floating point error.
     */
    public function toDecimal(): string
    {
        $factor = $this->currency->minorUnitFactor();
        $sign = $this->minorUnits < 0 ? '-' : '';
        $absolute = abs($this->minorUnits);

        return sprintf(
            '%s%d.%0'.$this->currency->scale().'d',
            $sign,
            intdiv($absolute, $factor),
            $absolute % $factor,
        );
    }

    /**
     * A human-readable amount with thousands separators and currency symbol.
     */
    public function format(bool $withSymbol = true): string
    {
        $formatted = number_format(
            (float) $this->toDecimal(),
            $this->currency->scale(),
            '.',
            ',',
        );

        return $withSymbol
            ? $this->currency->symbol().$formatted
            : $formatted;
    }

    /**
     * Shape sent to the front end, so React renders money without ever doing
     * arithmetic on it.
     *
     * @return array{minor_units: int, currency: string, decimal: string, formatted: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'minor_units' => $this->minorUnits,
            'currency' => $this->currency->value,
            'decimal' => $this->toDecimal(),
            'formatted' => $this->format(),
        ];
    }

    public function __toString(): string
    {
        return $this->format();
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw CurrencyMismatch::between($this->currency, $other->currency);
        }
    }
}
