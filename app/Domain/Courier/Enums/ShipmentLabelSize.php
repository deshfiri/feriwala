<?php

namespace App\Domain\Courier\Enums;

/**
 * The thermal label stocks a shipping label is laid out for. Each prints at
 * exactly its physical size, one label per page.
 */
enum ShipmentLabelSize: string
{
    /** Compact: recipient, COD, barcode and references. */
    case ThreeByThree = '3x3';

    /** Detailed: adds the Storefront reference, counts, instructions and full courier details. */
    case ThreeByFour = '3x4';

    public function widthInches(): int
    {
        return 3;
    }

    public function heightInches(): int
    {
        return match ($this) {
            self::ThreeByThree => 3,
            self::ThreeByFour => 4,
        };
    }

    public function barcodeHeightInches(): float
    {
        return match ($this) {
            self::ThreeByThree => 0.36,
            self::ThreeByFour => 0.55,
        };
    }

    public function isDetailed(): bool
    {
        return $this === self::ThreeByFour;
    }
}
