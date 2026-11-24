<?php

namespace App\Domain\Order\Enums;

use App\Support\Status\HasTranslatedLabel;

/**
 * What an applied revision meant for the courier (D38 "State rules").
 *
 *   - **None** — nothing the courier holds changed.
 *   - **NotBooked** — it changed something a courier would carry, but no
 *     courier had been booked, so the booking will use the corrected order.
 *   - **RecordedNotSent** — a contact correction recorded after pickup; the
 *     courier still has what it was given, and the order says so.
 *
 * There is deliberately no "courier updated": no courier Banij uses can be
 * updated through its API, and Banij never claims a change it cannot confirm.
 */
enum RevisionCourierConsequence: string
{
    use HasTranslatedLabel;

    case None = 'none';
    case NotBooked = 'not_booked';
    case RecordedNotSent = 'recorded_not_sent';

    protected static function statusLabelGroup(): string
    {
        return 'revision_courier';
    }
}
