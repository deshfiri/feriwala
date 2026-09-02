<?php

namespace App\Domain\Kyc\Exceptions;

use RuntimeException;

/**
 * A KYC round was submitted without everything the administrator asked for.
 */
class KycIncomplete extends RuntimeException
{
    /**
     * @param  array<int, string>  $missing
     */
    public function __construct(
        string $message,
        public readonly array $missing = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @param  array<int, string>  $missing
     */
    public static function missing(array $missing): self
    {
        return new self(
            'These are still required: '.implode(', ', $missing).'.',
            $missing,
        );
    }
}
