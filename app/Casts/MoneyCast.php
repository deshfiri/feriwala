<?php

namespace App\Casts;

use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Casts an integer minor-units column into a Money value object.
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

        return Money::of((int) $value, $this->resolveCurrency($attributes));
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

        if (is_int($value)) {
            return [$key => $value];
        }

        if (! $value instanceof Money) {
            throw new InvalidArgumentException(
                sprintf('The [%s] attribute must be a %s instance or integer minor units.', $key, Money::class),
            );
        }

        $written = [$key => $value->minorUnits];

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
