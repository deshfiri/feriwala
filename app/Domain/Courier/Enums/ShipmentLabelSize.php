<?php

namespace App\Domain\Courier\Enums;

/**
 * The thermal label stocks a shipping label is laid out for. Each prints at
 * exactly its physical size, one label per page.
 */
enum ShipmentLabelSize: string
{
    /** Compact: the same blocks, with the item list condensed to one line. */
    case ThreeByThree = '3x3';

    /** Detailed: room for a longer address and up to three item lines. */
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
            self::ThreeByThree => 0.32,
            self::ThreeByFour => 0.46,
        };
    }

    /** The most room the QR code may take, quiet zone included, in inches square. */
    public function qrMaxInches(): float
    {
        return match ($this) {
            self::ThreeByThree => 0.6,
            self::ThreeByFour => 0.75,
        };
    }

    /** How many item lines are listed before the rest are counted as "+N more". */
    public function itemLines(): int
    {
        return match ($this) {
            self::ThreeByThree => 1,
            self::ThreeByFour => 3,
        };
    }

    public function isDetailed(): bool
    {
        return $this === self::ThreeByFour;
    }
}
