<?php

namespace App\Domain\Courier\Exceptions;

use App\Domain\Courier\Enums\CourierProviderCode;
use RuntimeException;

/**
 * An operation the courier's API does not offer (as far as Banij has verified
 * its contract) -- refused, never faked.
 */
class UnsupportedCourierOperation extends RuntimeException
{
    public static function for(CourierProviderCode $provider, string $operation): self
    {
        return new self("{$provider->label()} has no {$operation} that Banij can call.");
    }
}
