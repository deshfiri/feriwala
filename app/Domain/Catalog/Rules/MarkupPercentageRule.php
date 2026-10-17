<?php

namespace App\Domain\Catalog\Rules;

use App\Domain\Catalog\WholesalePriceCalculator;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A markup percentage typed by a person: 0 to 1000, at most two decimals.
 *
 * Malformed, negative, scientific, infinite and over-precise values are refused
 * rather than rounded into a different percentage than the one entered.
 */
class MarkupPercentageRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! WholesalePriceCalculator::isValidPercentage($value)) {
            $fail(__('catalog.pricing.markup_invalid', ['max' => WholesalePriceCalculator::MAX_PERCENTAGE]));
        }
    }
}
