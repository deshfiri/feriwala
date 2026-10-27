<?php

namespace App\Domain\Damage\Enums;

use App\Support\Status\HasTranslatedLabel;

/**
 * How a damage case came to be opened (D35).
 *
 * A damage is a finding about goods, never a standalone record: it is found on
 * goods that came back with a return, or reported on a product that was
 * delivered. Either way it points at the order line it concerns.
 */
enum DamageSource: string
{
    use HasTranslatedLabel;

    /** Found while inspecting a returned product. */
    case ReturnInspection = 'return_inspection';

    /** A delivered product reported as damaged. */
    case DeliveredReport = 'delivered_report';

    protected static function statusLabelGroup(): string
    {
        return 'damage_source';
    }
}
