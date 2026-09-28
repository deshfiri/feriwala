<?php

namespace App\Domain\Address\Enums;

/**
 * Address kinds a `BusinessAccount` (Client/Partner) may hold.
 */
enum ClientAddressType: string
{
    case Business = 'business';
    case Operational = 'operational';

    public function label(): string
    {
        return match ($this) {
            self::Business => 'Business address',
            self::Operational => 'Operational address',
        };
    }
}
