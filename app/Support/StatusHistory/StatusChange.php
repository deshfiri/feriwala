<?php

namespace App\Support\StatusHistory;

use Carbon\CarbonImmutable;

/**
 * What a status history records about one change, beyond the two states (P0-16).
 *
 * The shared contract every status history keeps — previous and new status are
 * the states themselves; this carries the rest: **who** made the change (null
 * when the system did), **when**, **why**, a note for the people running the
 * platform, and a note the account holder may read. §18.3 asks the same of an
 * order, and the other histories §5.3, §16.4 and §27.5 describe ask no less.
 *
 * Kept apart from anything a particular history adds (a source, a notification
 * status), so the contract cannot grow a column one subject needs and every
 * other subject has to invent a value for.
 */
readonly class StatusChange
{
    public function __construct(
        public ?int $actorId = null,
        public ?string $reason = null,
        public ?string $internalNote = null,
        public ?string $publicNote = null,
        public ?CarbonImmutable $at = null,
    ) {}

    /**
     * A change the system made on its own: a sweep, a gateway callback.
     */
    public static function bySystem(?string $reason = null, ?string $publicNote = null): self
    {
        return new self(reason: $reason, publicNote: $publicNote);
    }
}
