<?php

namespace App\Domain\Address\Enums;

/**
 * Address kinds a `BusinessAccount` (Client/Partner) may hold.
 */
enum ClientAddressType: string
{
    case Business = 'business';
    case Operational = 'operational';

    /** The owner's home address in Bangladesh, given at onboarding (D39). */
    case Residential = 'residential';

    public function label(): string
    {
        return match ($this) {
            self::Business => 'Business address',
            self::Operational => 'Operational address',
            self::Residential => 'Residential address',
        };
    }
}
