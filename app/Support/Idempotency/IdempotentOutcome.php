<?php

namespace App\Support\Idempotency;

/**
 * The result of an idempotent operation, and whether it actually ran.
 *
 * @template TValue
 */
class IdempotentOutcome
{
    /**
     * @param  TValue  $value
     */
    public function __construct(
        public readonly mixed $value,
        public readonly bool $replayed,
    ) {}

    /**
     * True when this call performed the work.
     */
    public function executed(): bool
    {
        return ! $this->replayed;
    }
}
