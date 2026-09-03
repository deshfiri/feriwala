<?php

namespace App\Domain\Account\Exceptions;

use RuntimeException;

/**
 * An account was put forward for activation without meeting §5.1's conditions.
 *
 * Carries every unmet condition rather than the first one found, so an
 * administrator sees the whole picture instead of fixing one thing and being
 * refused again.
 */
class ActivationBlocked extends RuntimeException
{
    /**
     * @param  array<int, string>  $reasons
     */
    public function __construct(
        string $message,
        public readonly array $reasons = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @param  array<int, string>  $reasons
     */
    public static function because(array $reasons): self
    {
        return new self(
            'This account cannot be activated yet: '.implode(' ', $reasons),
            $reasons,
        );
    }
}
