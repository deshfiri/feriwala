<?php

namespace App\Domain\Damage\Data;

use App\Domain\Damage\Enums\DamageValuationBasis;
use App\Support\Money\Money;

/**
 * The most a damage can cost, and exactly how that was worked out (D35).
 *
 * Frozen onto the case when it is opened. The snapshot says which line figure it
 * came from and with what quantities, so the number can be explained without
 * going back to a price that may have moved.
 */
final class DamageValue
{
    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function __construct(
        public readonly DamageValuationBasis $basis,
        public readonly ?Money $unitPrice,
        public readonly Money $maximum,
        public readonly array $snapshot,
    ) {}
}
