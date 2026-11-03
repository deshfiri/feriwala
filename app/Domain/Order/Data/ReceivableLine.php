<?php

namespace App\Domain\Order\Data;

use App\Domain\Order\Enums\ReceivableComponent;
use App\Support\Money\Money;

/**
 * One worked-out component of a platform receivable, before it is written (D36).
 */
readonly class ReceivableLine
{
    public function __construct(
        public ReceivableComponent $component,
        public string $key,
        public ?int $orderItemId,
        public ?string $basis,
        public ?string $taxMode,
        public string $description,
        public int $quantity,
        public ?Money $unitAmount,
        public Money $amount,
        public string $ruleReference,
        /** The tax as it was assessed, for a tax component: what it contributes may be less (tax already inside a price is not added again). */
        public ?Money $assessedAmount = null,
    ) {}
}
