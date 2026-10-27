<?php

namespace App\Domain\Damage\Enums;

use App\Support\Status\HasTranslatedLabel;

/**
 * Where a damaged item's journey to its owner has got to (D35).
 *
 * A dispatch only moves forward, once: requested by the Partner who pays for the
 * delivery, dispatched by staff with a tracking reference, and received by the
 * Partner.
 */
enum DamageDispatchStatus: string
{
    use HasTranslatedLabel;

    case Requested = 'requested';
    case Dispatched = 'dispatched';
    case ReceivedByPartner = 'received_by_partner';

    protected static function statusLabelGroup(): string
    {
        return 'damage_dispatch';
    }
}
