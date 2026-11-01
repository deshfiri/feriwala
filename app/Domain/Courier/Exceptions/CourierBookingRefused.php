<?php

namespace App\Domain\Courier\Exceptions;

use RuntimeException;

/**
 * A booking Banij would not even start -- before any attempt exists and before
 * any call is made. The message is safe to show: fixed, translated text.
 */
class CourierBookingRefused extends RuntimeException
{
    public static function because(string $key): self
    {
        return new self(__("courier.booking.refused.{$key}"));
    }
}
