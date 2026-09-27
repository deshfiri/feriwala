<?php

namespace App\Domain\Order\Enums;

use App\Support\Status\HasTranslatedLabel;

/**
 * Where one source allocation stands.
 *
 * Only `Active` is a live claim on stock, and only one allocation per order
 * line may hold it — a partial unique index makes that the database's rule
 * rather than the action's. The other three are all history, distinguished
 * because *why* an allocation stopped being the answer matters when reading a
 * line back:
 *
 *   - **Released** — the reservation was given up and nothing replaced it.
 *   - **Superseded** — a reallocation replaced it, and
 *     `superseded_by_allocation_id` says with what.
 *   - **Cancelled** — the order or the line went away beneath it.
 */
enum AllocationStatus: string
{
    use HasTranslatedLabel;

    case Active = 'active';
    case Released = 'released';
    case Superseded = 'superseded';
    case Cancelled = 'cancelled';

    protected static function statusLabelGroup(): string
    {
        return 'allocation';
    }

    public function isActive(): bool
    {
        return $this === self::Active;
    }

    public function tone(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Superseded => 'info',
            self::Released, self::Cancelled => 'neutral',
        };
    }
}
