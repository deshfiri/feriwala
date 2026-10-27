<?php

namespace App\Domain\Damage\Enums;

use App\Support\Status\HasTranslatedLabel;

/**
 * What state the item is in (D35): a description of the goods, not a verdict on
 * who is responsible — that is {@see DamageCause}, and it is decided by staff.
 */
enum DamageCondition: string
{
    use HasTranslatedLabel;

    case Broken = 'broken';
    case Torn = 'torn';
    case Stained = 'stained';
    case Defective = 'defective';
    case MissingParts = 'missing_parts';
    case WaterDamaged = 'water_damaged';
    case Crushed = 'crushed';
    case Other = 'other';

    protected static function statusLabelGroup(): string
    {
        return 'damage_condition';
    }
}
