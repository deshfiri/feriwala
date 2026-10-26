<?php

namespace App\Domain\Order\Enums;

/**
 * What a case asks for (D35): the goods back and the money settled, or the
 * goods back and a replacement sent.
 *
 * A damage claim is not a third type of case: it is a finding about goods, made
 * at inspection or on a delivered product, and has its own record that points at
 * the case or the order it concerns.
 */
enum ReturnCaseType: string
{
    case Return = 'return';
    case Exchange = 'exchange';

    public function label(): string
    {
        return match ($this) {
            self::Return => 'Return',
            self::Exchange => 'Exchange',
        };
    }

    public function isExchange(): bool
    {
        return $this === self::Exchange;
    }
}
