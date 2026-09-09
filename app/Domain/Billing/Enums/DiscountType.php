<?php

namespace App\Domain\Billing\Enums;

/**
 * How a coupon's value is read (§9).
 */
enum DiscountType: string
{
    /** `value` is basis points — 1500 is 15%. */
    case Percentage = 'percentage';

    /** `value` is minor units of the coupon's own currency. */
    case Fixed = 'fixed';

    public function label(): string
    {
        return match ($this) {
            self::Percentage => 'Percentage',
            self::Fixed => 'Fixed amount',
        };
    }
}
