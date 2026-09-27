<?php

namespace App\Domain\Supplier\Enums;

use App\Support\Status\HasTranslatedLabel;

/**
 * Whether one Supplier's offer on one product/variation is currently
 * purchasable (D25, P13-14).
 */
enum OfferStatus: string
{
    use HasTranslatedLabel;

    case Active = 'active';
    case Suspended = 'suspended';

    protected static function statusLabelGroup(): string
    {
        return 'offer';
    }

    public function tone(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Suspended => 'danger',
        };
    }
}
