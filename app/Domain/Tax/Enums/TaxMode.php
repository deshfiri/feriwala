<?php

namespace App\Domain\Tax\Enums;

/**
 * Whether a price already contains its tax (D19).
 *
 * **Exclusive is the default**, and deliberately so: a fee configured at 500 BDT
 * means 500 BDT of revenue, with tax added on top. Under inclusive pricing the
 * same 500 would silently become 434.78 of revenue and 65.22 of tax, and an
 * administrator who changed the mode without meaning to would change what
 * Feriwala earns on every sale.
 *
 * Both are supported because a retail price shown to a consumer is usually
 * quoted tax-inclusive, while a B2B fee is not.
 */
enum TaxMode: string
{
    /** Tax is added to the amount. The amount is net. */
    case Exclusive = 'exclusive';

    /** Tax is already inside the amount. The amount is gross. */
    case Inclusive = 'inclusive';

    public static function default(): self
    {
        return self::Exclusive;
    }

    public function label(): string
    {
        return match ($this) {
            self::Exclusive => 'Tax added on top',
            self::Inclusive => 'Tax included in the price',
        };
    }

    public function isInclusive(): bool
    {
        return $this === self::Inclusive;
    }
}
