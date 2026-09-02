<?php

namespace App\Domain\Account\Exceptions;

use RuntimeException;

/**
 * A verification code was requested again before the cooldown elapsed.
 *
 * Surfaces to the user as a wait, not an error — they have done nothing wrong.
 */
class ResendTooSoon extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $secondsRemaining = 0,
    ) {
        parent::__construct($message);
    }

    public static function wait(int $seconds): self
    {
        return new self(
            "Please wait {$seconds} seconds before requesting another code.",
            $seconds,
        );
    }
}
