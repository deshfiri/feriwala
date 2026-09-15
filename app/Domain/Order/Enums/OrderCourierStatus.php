<?php

namespace App\Domain\Order\Enums;

/**
 * Where an order stands with a courier (§18, §21).
 *
 * Carried from the start, as §18 requires; courier management (P6.C) adds the
 * states after assignment.
 */
enum OrderCourierStatus: string
{
    case Unassigned = 'unassigned';

    public function label(): string
    {
        return match ($this) {
            self::Unassigned => 'No courier assigned',
        };
    }
}
