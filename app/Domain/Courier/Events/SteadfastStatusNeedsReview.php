<?php

namespace App\Domain\Courier\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A Steadfast parcel has just been flagged for a person to look at, once --
 * dispatched after the change commits, and only on the move from not flagged to
 * flagged, so a repeated webhook or poll never announces it twice.
 *
 * Carries ids and Steadfast's raw status only: no customer data, no payload.
 */
class SteadfastStatusNeedsReview
{
    use Dispatchable;

    public function __construct(
        public readonly int $shipmentId,
        public readonly string $rawStatus,
        public readonly bool $unknownStatus,
    ) {}
}
