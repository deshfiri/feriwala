<?php

namespace App\Domain\Courier\Exceptions;

use App\Domain\Courier\Enums\CourierProviderCode;
use RuntimeException;

/**
 * A shipment was asked to go through a provider nobody can actually use
 * (§21, D8).
 */
class CourierProviderUnavailable extends RuntimeException
{
    public static function forProvider(CourierProviderCode $code, bool $isImplemented): self
    {
        return new self($isImplemented
            ? "{$code->label()} is switched off."
            : "{$code->label()} requires provider credentials this application does not have yet.");
    }
}
