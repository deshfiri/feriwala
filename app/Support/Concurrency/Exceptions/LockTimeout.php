<?php

namespace App\Support\Concurrency\Exceptions;

use RuntimeException;

class LockTimeout extends RuntimeException
{
    public static function forKey(string $key, int $waitSeconds): self
    {
        return new self(sprintf(
            'Could not acquire lock [%s] within %d second(s). Another process is holding it.',
            $key,
            $waitSeconds,
        ));
    }
}
