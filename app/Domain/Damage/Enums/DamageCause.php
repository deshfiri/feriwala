<?php

namespace App\Domain\Damage\Enums;

use App\Support\Status\HasTranslatedLabel;

/**
 * What the inspector found caused it (D35).
 *
 * The cause informs who bears the loss but does not decide it: a person with
 * the right to assign liability does, in their own words, at liability review.
 * Banij's own packing, warehouse or handling faults are Banij's to bear; the
 * Partner's own handling, and a customer's misuse, are the Partner's — customer
 * recovery stays the Partner's business, outside Banij.
 */
enum DamageCause: string
{
    use HasTranslatedLabel;

    case Packing = 'packing';
    case WarehouseHandling = 'warehouse_handling';
    case CourierTransit = 'courier_transit';
    case PartnerHandling = 'partner_handling';
    case CustomerMisuse = 'customer_misuse';
    case ManufacturingDefect = 'manufacturing_defect';
    case Unknown = 'unknown';

    protected static function statusLabelGroup(): string
    {
        return 'damage_cause';
    }
}
