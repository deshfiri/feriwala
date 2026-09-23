<?php

namespace App\Casts;

use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Casts a `NUMERIC(19,2)` flat-Taka column into a Money value object.
 *
 * What the column holds is what the Money holds — `100.50` both sides, with no
 * scaling in either direction (D26).
 *
 * The currency is read from a sibling column — `currency_code` by default (D4) —
 * so a row always carries its own currency rather than inheriting an ambient one.
 *
 * Usage:
 *
 *     protected function casts(): array
 *     {
 *         return [
 *             'amount' => MoneyCast::class,                    // uses currency_code
 *             'fee' => MoneyCast::class.':fee_currency_code',   // uses its own column
 *         ];
 *     }
 *
 * The set type is `mixed` on purpose: this cast is a boundary, and callers do get
 * it wrong. Declaring it narrowly would only hide the guard in set(), not stop a
 * string or float arriving from a request or a seeder.
 *
 * @implements CastsAttributes<Money|null, mixed>
 */
class MoneyCast implements CastsAttributes
{
    public function __construct(
        protected string $currencyColumn = 'currency_code',
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        if ($value === null) {
            return null;
        }

        return Money::fromDecimal((string) $value, $this->resolveCurrency($attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$key => null];
        }

        // A bare number is refused rather than interpreted. Under the old
        // minor-unit architecture `100` meant one Taka here; under D26 it would
        // mean a hundred. Anything that still hands this cast a raw number is
        // code written against the old meaning, and guessing which one it
        // intended is precisely how a 100-times error gets written to a ledger.
        if (! $value instanceof Money) {
            throw new InvalidArgumentException(
                sprintf('The [%s] attribute must be a %s instance, not a bare number (D26).', $key, Money::class),
            );
        }

        $written = [$key => $value->amount];

        // Keep the currency column in step with the amount, but never silently
        // rewrite an existing currency to a different one — that would corrupt a
        // ledger row's meaning (D4).
        $existing = $attributes[$this->currencyColumn] ?? null;

        if ($existing !== null && $existing !== $value->currency->value) {
            throw new InvalidArgumentException(sprintf(
                'Refusing to write %s into [%s] while [%s] is %s. Change the currency explicitly.',
                $value->currency->value,
                $key,
                $this->currencyColumn,
                $existing,
            ));
        }

        $written[$this->currencyColumn] = $value->currency->value;

        return $written;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function resolveCurrency(array $attributes): Currency
    {
        $code = $attributes[$this->currencyColumn] ?? null;

        if ($code === null) {
            return Currency::base();
        }

        return Currency::from((string) $code);
    }
}
