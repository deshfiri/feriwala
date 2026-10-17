<?php

namespace App\Domain\Inventory\Enums;

/**
 * Why a Partner's own stock moved (D34).
 *
 * The buckets a movement takes from and puts into say what happened to the
 * units; the type says why. Held to these values by CHECK.
 */
enum PartnerStockMovementType: string
{
    /** A paid wholesale purchase gave the Partner these units. */
    case PurchaseReceived = 'purchase_received';

    /** A purchase stopped counting, so its units left the Partner's stock. */
    case PurchaseReversed = 'purchase_reversed';

    /** Set aside for a storefront order not yet confirmed. */
    case Reservation = 'reservation';

    /** A reservation given back before it ran out. */
    case ReservationReleased = 'reservation_released';

    /** A reservation that ran out and was released by the scheduler. */
    case ReservationExpired = 'reservation_expired';

    /** A reservation turned into stock committed to a confirmed order. */
    case ReservationCommitted = 'reservation_committed';

    /** Goods back from a customer, judged and put where they belong. */
    case ReturnReceived = 'return_received';

    /** Changed by hand by an authorised person, with a reason. */
    case Adjustment = 'adjustment';
}
