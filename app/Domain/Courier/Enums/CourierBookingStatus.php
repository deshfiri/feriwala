<?php

namespace App\Domain\Courier\Enums;

/**
 * Where an attempt to book a parcel with a courier stands.
 *
 * Not an order status: a failed booking changes nothing about the order. At
 * most one attempt per order is live (pending, booked or uncertain), enforced
 * by a partial unique index.
 */
enum CourierBookingStatus: string
{
    /** Recorded; the provider has not answered yet. */
    case Pending = 'pending';

    /** The provider confirmed and the shipment exists. */
    case Booked = 'booked';

    /** The provider refused, or the request provably never reached it. Safe to try again. */
    case Failed = 'failed';

    /** The reply was lost: the provider may or may not hold the parcel. Never retried blind. */
    case Uncertain = 'uncertain';

    public function isLive(): bool
    {
        return $this !== self::Failed;
    }

    public function label(): string
    {
        return __("courier.booking.status.{$this->value}");
    }
}
