<?php

namespace App\Domain\Order\Enums;

/**
 * Whether a status change was announced, as decided when it happened (§18.3).
 *
 * A history row is written once and never changed, so it records the decision
 * made at the moment of the change — nobody needed telling, or a notification was
 * queued — rather than a delivery outcome that only arrives later. Whether a
 * queued notification reached anyone is the notification log's to say (P8).
 */
enum OrderNotificationStatus: string
{
    case NotRequired = 'not_required';
    case Queued = 'queued';

    public function label(): string
    {
        return match ($this) {
            self::NotRequired => 'No notification',
            self::Queued => 'Notification queued',
        };
    }
}
