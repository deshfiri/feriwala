<?php

namespace App\Domain\Inventory\Data;

/**
 * Who moved stock, why, and what the movement answers to.
 *
 * Immutable, and built where the reason is actually known — reconstructing it
 * later is how a history ends up recording "stock changed".
 */
final class MovementContext
{
    public function __construct(
        public readonly ?string $reason = null,
        public readonly ?int $actorId = null,
        public readonly ?string $sourceType = null,
        public readonly ?int $sourceId = null,
        public readonly ?string $idempotencyKey = null,
    ) {}
}
