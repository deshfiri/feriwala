<?php

namespace App\Domain\Website\Exceptions;

use RuntimeException;

/**
 * A change to a website's customer the ERP will not make (contract §4.5, §6.2).
 */
class WebsiteCustomerRefused extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function invalidMobileNumber(): self
    {
        return new self(422, 'invalid_mobile_number', 'The customer\'s mobile number could not be resolved.');
    }

    public static function mobileTaken(): self
    {
        return new self(409, 'customer_mobile_taken', 'Another customer of this website has that mobile number.');
    }

    public static function referenceTaken(): self
    {
        return new self(409, 'customer_reference_taken', 'Another customer of this website has that storefront reference.');
    }
}
