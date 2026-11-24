<?php

namespace App\Domain\Order\Enums;

use App\Support\Status\HasTranslatedLabel;

/**
 * What a revision did to one order line (D38). A line it left alone has no
 * revision line at all.
 */
enum RevisionLineChange: string
{
    use HasTranslatedLabel;

    case Added = 'added';
    case Removed = 'removed';
    case QuantityChanged = 'quantity_changed';
    case VariantChanged = 'variant_changed';
    case PriceChanged = 'price_changed';

    protected static function statusLabelGroup(): string
    {
        return 'revision_line';
    }

    /** Whether the old line stops being part of the order. */
    public function supersedesPreviousLine(): bool
    {
        return $this !== self::Added;
    }

    /** Whether a new line takes the old one's place, or is added. */
    public function introducesLine(): bool
    {
        return $this !== self::Removed;
    }
}
