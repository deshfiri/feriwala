<?php

namespace App\Domain\Billing\Enums;

use App\Support\Status\HasTranslatedLabel;

/**
 * The two customer delivery areas (D37), frozen as `orders.delivery_area`.
 *
 * Decided by the delivery address's canonical BdLocation district only:
 * Dhaka district is Inside Dhaka, every other Bangladesh district is Outside
 * Dhaka. Never by address text, postcode or division.
 */
enum DeliveryArea: string
{
    use HasTranslatedLabel;

    case InsideDhaka = 'inside_dhaka';
    case OutsideDhaka = 'outside_dhaka';

    /** The fee rule each area is priced by. */
    public function feeType(): FeeType
    {
        return match ($this) {
            self::InsideDhaka => FeeType::CustomerDeliveryInsideDhaka,
            self::OutsideDhaka => FeeType::WebsiteDelivery,
        };
    }

    protected static function statusLabelGroup(): string
    {
        return 'delivery_area';
    }
}
