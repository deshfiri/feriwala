<?php

namespace App\Domain\Address\Enums;

/**
 * Address kinds a Supplier may hold.
 */
enum SupplierAddressType: string
{
    case Registered = 'registered';
    case Pickup = 'pickup';
    case Return = 'return';

    public function label(): string
    {
        return match ($this) {
            self::Registered => 'Registered address',
            self::Pickup => 'Pickup address',
            self::Return => 'Return address',
        };
    }
}
