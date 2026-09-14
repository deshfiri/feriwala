<?php

namespace App\Domain\Inventory\Enums;

/**
 * Why central stock moved (§19, §19.1).
 *
 * The buckets a movement takes from and puts into say what happened to the
 * units; the type says why. Held to these values by CHECK, so a movement
 * written outside the ledger cannot invent a cause.
 */
enum StockMovementType: string
{
    /** Changed by hand by an authorised person, with a reason (P3-24). */
    case Adjustment = 'adjustment';

    /** Set aside for an order not yet confirmed (P3-25). */
    case Reservation = 'reservation';

    /** A reservation given back before it ran out (P3-25). */
    case ReservationReleased = 'reservation_released';

    /** A reservation that ran out and was released by the scheduler (P3-26). */
    case ReservationExpired = 'reservation_expired';

    /** A reservation turned into stock committed to a confirmed order (P3-25). */
    case ReservationCommitted = 'reservation_committed';

    public function label(): string
    {
        return match ($this) {
            self::Adjustment => 'Adjustment',
            self::Reservation => 'Reserved',
            self::ReservationReleased => 'Reservation released',
            self::ReservationExpired => 'Reservation expired',
            self::ReservationCommitted => 'Reservation committed',
        };
    }
}
