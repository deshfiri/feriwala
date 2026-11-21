<?php

namespace App\Domain\Billing\Exceptions;

use RuntimeException;

/**
 * A customer order that cannot be given a delivery charge (D37): its address
 * has no district we can resolve, or no rate is configured for its area. Both
 * fail closed -- an order is never sent out charging nothing by accident.
 */
class DeliveryChargeRefused extends RuntimeException
{
    public const ADDRESS_UNRESOLVED = 'delivery_address_required';

    public const NOT_CONFIGURED = 'delivery_not_configured';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function addressUnresolved(): self
    {
        return new self(self::ADDRESS_UNRESOLVED, 'Choose a valid delivery address in Bangladesh, down to its district.');
    }

    public static function notConfigured(): self
    {
        return new self(self::NOT_CONFIGURED, 'Delivery charge is not configured.');
    }
}
