<?php

namespace App\Domain\Courier\Exceptions;

use RuntimeException;

/**
 * Courier references Banij would not record against a shipment. The message is
 * safe to show: fixed, translated text.
 */
class CourierReferencesRefused extends RuntimeException
{
    public static function because(string $key): self
    {
        return new self(__("courier.references.refused.{$key}"));
    }
}
