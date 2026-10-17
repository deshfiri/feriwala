<?php

namespace App\Domain\Catalog\Enums;

/**
 * How a product's (or variation's) wholesale price comes to be.
 *
 * Fixed: staff type the price. Percentage: the price is the base cost plus a
 * markup percentage, worked out on the server and never taken from the browser.
 */
enum WholesalePricingMethod: string
{
    case Fixed = 'fixed';
    case Percentage = 'percentage';

    public function label(): string
    {
        return match ($this) {
            self::Fixed => 'Fixed price',
            self::Percentage => 'Percentage markup',
        };
    }

    public function isPercentage(): bool
    {
        return $this === self::Percentage;
    }
}
