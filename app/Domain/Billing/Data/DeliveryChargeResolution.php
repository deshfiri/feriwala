<?php

namespace App\Domain\Billing\Data;

use App\Domain\Billing\Enums\DeliveryArea;
use App\Domain\Billing\Models\FeeRule;
use App\Domain\Location\Models\BdLocation;
use App\Support\Money\Money;

/**
 * The customer delivery charge for one order (D37), and how it was reached:
 * the area the address's district falls in, and the rule that priced it.
 *
 * One charge per order: never multiplied by product, quantity or parcel.
 */
readonly class DeliveryChargeResolution
{
    public function __construct(
        public Money $amount,
        public DeliveryArea $area,
        public BdLocation $district,
        public FeeRule $rule,
    ) {}

    /** The rule sets the charge to zero on purpose: shown as "Free delivery". */
    public function isFree(): bool
    {
        return $this->amount->isZero();
    }
}
