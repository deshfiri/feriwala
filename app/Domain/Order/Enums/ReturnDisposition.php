<?php

namespace App\Domain\Order\Enums;

use App\Domain\Inventory\Enums\StockBucket;

/**
 * What was done with goods that came back (§19.1, P6-12).
 *
 * Chosen by whoever inspected them, per line, and never assumed: putting a
 * returned item back on sale is a decision about whether it is fit to sell, and
 * a system that decides it silently sells somebody a damaged shirt.
 *
 * Each disposition is a bucket the units end in, so the stock ledger explains
 * where they went in the same words the person used.
 */
enum ReturnDisposition: string
{
    /** Fit to sell again: back on the shelf. */
    case Restock = 'restock';

    /** Held, and not sellable. */
    case Damaged = 'damaged';

    /** Back, but not yet judged — held apart until somebody decides. */
    case Quarantine = 'quarantine';

    /**
     * The bucket these units end in.
     *
     * Quarantine stays in `returned`, which is the bucket for goods back from a
     * customer and awaiting inspection — exactly what a quarantined unit is.
     */
    public function bucket(): StockBucket
    {
        return match ($this) {
            self::Restock => StockBucket::Available,
            self::Damaged => StockBucket::Damaged,
            self::Quarantine => StockBucket::Returned,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Restock => 'Back on sale',
            self::Damaged => 'Damaged',
            self::Quarantine => 'Held for inspection',
        };
    }

    /**
     * Whether these units become sellable again.
     */
    public function isSellable(): bool
    {
        return $this === self::Restock;
    }
}
