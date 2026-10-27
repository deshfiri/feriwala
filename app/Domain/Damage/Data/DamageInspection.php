<?php

namespace App\Domain\Damage\Data;

use App\Domain\Damage\Enums\DamageCause;
use App\Domain\Damage\Enums\DamageCondition;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Order\Enums\ReturnDisposition;

/**
 * What an inspector found (D35): what ended up where, and what caused it.
 *
 * Every affected unit is given exactly one disposition. Nothing here says who
 * bears the loss or what it costs; that is decided afterwards, at liability
 * review, by someone who may.
 */
final class DamageInspection
{
    /**
     * @param  list<array{disposition: ReturnDisposition, quantity: int}>  $dispositions  one per disposition, adding up to the case's quantity
     * @param  DamageCondition|null  $condition  the inspector's own finding of the item's condition, when it differs from the report
     * @param  Warehouse|null  $warehouse  where central stock is held, when the case does not already say
     */
    public function __construct(
        public readonly array $dispositions,
        public readonly DamageCause $cause,
        public readonly ?string $note = null,
        public readonly ?DamageCondition $condition = null,
        public readonly ?Warehouse $warehouse = null,
    ) {}
}
