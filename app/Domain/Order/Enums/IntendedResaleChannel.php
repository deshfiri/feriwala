<?php

namespace App\Domain\Order\Enums;

/**
 * Where a buyer says they mean to sell wholesale stock (D21, P4-14).
 *
 * **Reporting only.** Optional, chosen by the buyer, and read by nothing that
 * prices, reserves, allows or refuses anything. D21 is explicit that the ERP does
 * not track or block where independently bought stock is sold.
 */
enum IntendedResaleChannel: string
{
    case OwnWebsite = 'own_website';
    case SocialMedia = 'social_media';
    case Marketplace = 'marketplace';
    case PhysicalShop = 'physical_shop';
    case Wholesale = 'wholesale';
    case Other = 'other';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $channel) => $channel->value, self::cases());
    }
}
