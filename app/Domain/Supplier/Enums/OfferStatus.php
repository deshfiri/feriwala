<?php

namespace App\Domain\Supplier\Enums;

/**
 * Whether one Supplier's offer on one product/variation is currently
 * purchasable (D25, P13-14).
 */
enum OfferStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Suspended => 'Suspended',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Suspended => 'danger',
        };
    }
}
