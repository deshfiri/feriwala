<?php

namespace App\Domain\Referral\Enums;

/**
 * A reward is a fixed amount or a percentage of the commission base (§25.3, D24).
 */
enum RewardType: string
{
    case Fixed = 'fixed';
    case Percentage = 'percentage';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $type) => $type->value, self::cases());
    }
}
