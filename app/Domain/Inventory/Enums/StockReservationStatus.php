<?php

namespace App\Domain\Inventory\Enums;

use App\Support\StateMachine\TransitionableState;
use App\Support\Status\HasTranslatedLabel;

/**
 * Where a reservation stands (§19.1, contract §6.1.2).
 *
 * A reservation ends exactly once. Every end state is terminal, which is what
 * makes committing, releasing and expiring each happen once however often they
 * are retried: the second attempt finds no move left.
 */
enum StockReservationStatus: string implements TransitionableState
{
    use HasTranslatedLabel;

    /** Units held in the reserved bucket, waiting on payment or confirmation. */
    case Active = 'active';

    /** Payment verified or order confirmed; the units moved on to processing. */
    case Committed = 'committed';

    /** Given back before it ran out — a failed or cancelled payment, or a person's override. */
    case Released = 'released';

    /** Ran out, and the scheduler gave the units back (P3-26). */
    case Expired = 'expired';

    /**
     * @return array<int, self>
     */
    public function transitionsTo(): array
    {
        return match ($this) {
            self::Active => [self::Committed, self::Released, self::Expired],
            self::Committed, self::Released, self::Expired => [],
        };
    }

    public function isTerminal(): bool
    {
        return $this->transitionsTo() === [];
    }

    protected static function statusLabelGroup(): string
    {
        return 'stock_reservation';
    }

    public function tone(): string
    {
        return match ($this) {
            self::Active => 'info',
            self::Committed => 'success',
            self::Released, self::Expired => 'neutral',
        };
    }
}
