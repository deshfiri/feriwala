<?php

namespace App\Domain\Account\Enums;

/**
 * Address kinds an account can hold (§5.2).
 *
 * Present and permanent are separate fields in the registration form, not one
 * address with a flag, so they are separate types here.
 */
enum AddressType: string
{
    case Present = 'present';
    case Permanent = 'permanent';
    case Billing = 'billing';
    case Shipping = 'shipping';

    public function label(): string
    {
        return match ($this) {
            self::Present => 'Present address',
            self::Permanent => 'Permanent address',
            self::Billing => 'Billing address',
            self::Shipping => 'Shipping address',
        };
    }

    /**
     * Types collected during registration (§5.2), as opposed to those added
     * later when ordering.
     */
    public function isCollectedAtRegistration(): bool
    {
        return in_array($this, [self::Present, self::Permanent], true);
    }
}
