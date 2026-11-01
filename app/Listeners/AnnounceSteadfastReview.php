<?php

namespace App\Listeners;

use App\Domain\Courier\Events\SteadfastStatusNeedsReview;
use App\Domain\Courier\Models\Shipment;
use App\Domain\Notification\Actions\AnnounceCourierEvent;

/**
 * Tells the staff who handle couriers that a Steadfast parcel has been flagged,
 * once -- the event is only dispatched on the move from unflagged to flagged,
 * after the change commits.
 */
class AnnounceSteadfastReview
{
    public function __construct(protected AnnounceCourierEvent $announce) {}

    public function handle(SteadfastStatusNeedsReview $event): void
    {
        $shipment = Shipment::query()->with('order')->find($event->shipmentId);

        if ($shipment !== null) {
            $this->announce->statusNeedsReview($shipment, $event->unknownStatus);
        }
    }
}
