<?php

namespace App\Domain\Payout\Exceptions;

use RuntimeException;

/**
 * A payout method could not be saved as requested — always carries a reason
 * a person can act on (the same account is already registered).
 */
class PayoutMethodRefused extends RuntimeException
{
    public static function duplicateAccount(): self
    {
        return new self('This account is already registered as a payout method.');
    }
}
