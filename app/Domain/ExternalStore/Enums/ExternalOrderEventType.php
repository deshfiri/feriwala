<?php

namespace App\Domain\ExternalStore\Enums;

/**
 * What an entry in an external order's history records (D29).
 *
 * Only what the store actually reported. `Imported` carries the state the
 * order was in when Feriwala first saw it — not a history of how it got there,
 * which the store never sent.
 */
enum ExternalOrderEventType: string
{
    case Imported = 'imported';
    case StatusChanged = 'status_changed';
    case PaymentStatusChanged = 'payment_status_changed';
    case PaymentReceived = 'payment_received';
}
