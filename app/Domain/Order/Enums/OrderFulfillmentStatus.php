<?php

namespace App\Domain\Order\Enums;

/**
 * Where an order stands in fulfilment (§18, §20).
 *
 * Every order carries it from the start, as §18 requires; the states between
 * "nothing requested yet" and done arrive with fulfilment management (P6.B).
 */
enum OrderFulfillmentStatus: string
{
    case Unfulfilled = 'unfulfilled';

    public function label(): string
    {
        return match ($this) {
            self::Unfulfilled => 'Not yet fulfilled',
        };
    }
}
