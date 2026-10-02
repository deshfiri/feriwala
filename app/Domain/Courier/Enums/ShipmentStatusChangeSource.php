<?php

namespace App\Domain\Courier\Enums;

/**
 * What moved a shipment's status (Advanced Order Management batch, Commit 5).
 *
 * `Webhook` exists for a provider's own status push, even though no provider
 * in this batch sends one yet (D8: only the manual driver, with no webhook,
 * is implemented) -- the column accepts it now so a later provider's driver
 * needs no history-table change to record one.
 */
enum ShipmentStatusChangeSource: string
{
    case Staff = 'staff';
    case System = 'system';
    case Webhook = 'webhook';

    public function label(): string
    {
        return match ($this) {
            self::Staff => 'Staff',
            self::System => 'System',
            self::Webhook => 'Courier webhook',
        };
    }
}
