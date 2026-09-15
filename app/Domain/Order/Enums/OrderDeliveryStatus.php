<?php

namespace App\Domain\Order\Enums;

/**
 * Where an order stands in delivery (§18, §21).
 *
 * Carried from the start, as §18 requires; courier management (P6.C) adds the
 * states once an order ships.
 */
enum OrderDeliveryStatus: string
{
    case NotShipped = 'not_shipped';

    public function label(): string
    {
        return match ($this) {
            self::NotShipped => 'Not shipped yet',
        };
    }
}
