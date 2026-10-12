<?php

namespace App\Domain\ExternalStore\Exceptions;

use RuntimeException;

/**
 * A store's copy of an order that is not written down as it stands (D29).
 *
 *   - **Incomplete** — not enough to create a new order safely (no store id,
 *     an unknown amount, no items). Nothing is guessed: the order comes in
 *     complete through the pull API.
 *   - **Identity conflict** — the store's id and its order number point at
 *     two different orders already recorded, or an order number is reused for
 *     another of the store's orders. Merging them would put one order's
 *     history on another, so nothing is changed.
 *
 * The message names fields, never values.
 */
class ExternalOrderNotRecorded extends RuntimeException
{
    public function __construct(string $message, public readonly bool $incomplete)
    {
        parent::__construct($message);
    }

    public static function incomplete(string $missing): self
    {
        return new self($missing, incomplete: true);
    }

    public static function identityConflict(): self
    {
        return new self('the store id and order number belong to different orders', incomplete: false);
    }
}
