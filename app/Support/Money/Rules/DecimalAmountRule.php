<?php

namespace App\Support\Money\Rules;

use App\Support\Money\Currency;
use App\Support\Money\DecimalAmount;
use App\Support\Money\Exceptions\InvalidDecimalAmount;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates a human-entered Taka amount at the request boundary (§36.1).
 *
 * Use on the raw decimal-string field itself — `'amount' => ['required', new
 * DecimalAmountRule]` — which a human-facing form submits exactly as typed
 * (D26: flat Taka, never a scaled integer). The controller re-parses the same
 * string with {@see DecimalAmount::parse()} after validation passes, once,
 * rather than trusting a value this rule only checked the shape of.
 */
class DecimalAmountRule implements ValidationRule
{
    public function __construct(
        protected ?Currency $currency = null,
        protected bool $allowNegative = false,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            $fail('The :attribute must be an amount.');

            return;
        }

        try {
            DecimalAmount::parse((string) $value, $this->currency, $this->allowNegative);
        } catch (InvalidDecimalAmount $exception) {
            $fail($exception->getMessage());
        }
    }
}
