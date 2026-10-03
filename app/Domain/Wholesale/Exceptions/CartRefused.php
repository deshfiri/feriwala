<?php

namespace App\Domain\Wholesale\Exceptions;

use App\Support\Money\Money;
use RuntimeException;

/**
 * A cart change the rules do not allow, refused before anything is written
 * (§14, P4-4).
 *
 * Each refusal says what to do about it — "order at least 6", "only 4 are
 * available" — in the reader's language, and names the field it belongs to.
 */
class CartRefused extends RuntimeException
{
    public function __construct(string $message, public readonly string $field)
    {
        parent::__construct($message);
    }

    public static function unavailable(): self
    {
        return new self(__('wholesale.refused.unavailable'), 'product');
    }

    public static function chooseVariation(): self
    {
        return new self(__('wholesale.refused.choose_variation'), 'variant');
    }

    public static function variationUnavailable(): self
    {
        return new self(__('wholesale.refused.variation_unavailable'), 'variant');
    }

    public static function belowMinimum(int $minimum): self
    {
        return new self(__('wholesale.refused.below_minimum', ['min' => $minimum]), 'quantity');
    }

    public static function aboveMaximum(int $maximum): self
    {
        return new self(__('wholesale.refused.above_maximum', ['max' => $maximum]), 'quantity');
    }

    public static function insufficientStock(int $available): self
    {
        return new self(__('wholesale.refused.insufficient_stock', ['available' => $available]), 'quantity');
    }

    public static function cartFull(int $maximum): self
    {
        return new self(__('wholesale.refused.cart_full', ['max' => $maximum]), 'product');
    }

    /** A Non-Conditional account's line needs a declared resale/COD amount. */
    public static function resaleAmountRequired(): self
    {
        return new self(__('wholesale.refused.resale_amount_required_line'), 'resale_amount');
    }

    public static function resaleAmountBelowMinimum(Money $minimum): self
    {
        return new self(
            __('wholesale.refused.resale_amount_below_minimum', ['minimum' => $minimum->toDecimal()]),
            'resale_amount',
        );
    }

    public static function resaleAmountAboveMaximum(Money $maximum): self
    {
        return new self(
            __('wholesale.refused.resale_amount_above_maximum', ['maximum' => $maximum->toDecimal()]),
            'resale_amount',
        );
    }
}
