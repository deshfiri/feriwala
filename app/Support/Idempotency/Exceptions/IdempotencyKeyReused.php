<?php

namespace App\Support\Idempotency\Exceptions;

use RuntimeException;

/**
 * Thrown when an idempotency key is replayed with a different request body.
 *
 * Surfaces as HTTP 409 `idempotency_key_reused` on the storefront API
 * (see requirements/05-storefront-api-contract.md §4.7).
 */
class IdempotencyKeyReused extends RuntimeException
{
    public static function forKey(string $key): self
    {
        return new self(sprintf(
            'Idempotency key [%s] was already used for a different request. '
            .'Reusing a key with changed content is refused — send a new key.',
            $key,
        ));
    }
}
