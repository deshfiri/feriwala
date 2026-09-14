<?php

namespace App\Domain\Inventory\Enums;

/**
 * The states a unit of central stock can be in (§19).
 *
 * Each is a column on `stock_items`, named the same as the case value, so a
 * movement names the buckets it takes from and puts into and nothing else has
 * to translate.
 */
enum StockBucket: string
{
    /**
     * On the shelf and free to be sold to anyone. A reservation takes from here,
     * or from the ordering account's own allocation.
     */
    case Available = 'available';

    /** Spoken for by an order that is not yet confirmed (§19.1). */
    case Reserved = 'reserved';

    /** Committed to a confirmed order and on its way through fulfilment. */
    case Processing = 'processing';

    /** Delivered to a customer. */
    case Sold = 'sold';

    /** Back from a customer and awaiting inspection (§19.1). */
    case Returned = 'returned';

    /** Held but not sellable. */
    case Damaged = 'damaged';

    /**
     * Set aside for one business account (§19: user-allocated stock, P3-30).
     * Out of everyone else's reach; only that account's orders draw on it.
     */
    case Allocated = 'allocated';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Available',
            self::Reserved => 'Reserved',
            self::Processing => 'Processing',
            self::Sold => 'Sold',
            self::Returned => 'Returned',
            self::Damaged => 'Damaged',
            self::Allocated => 'Allocated',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $bucket) => $bucket->value, self::cases());
    }
}
