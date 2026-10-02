<?php

namespace App\Domain\Billing\Data;

use App\Domain\Billing\CalculateDeliveryCharge;
use App\Domain\Billing\Models\DeliveryChargeRule;
use App\Support\Money\Money;

/**
 * What {@see CalculateDeliveryCharge} worked out, and
 * every figure that went into it (beta-critical batch, Commit 2).
 *
 * {@see toArray()} is exactly the immutable snapshot {@see
 * \App\Domain\Courier\Actions\CreateShipmentFromAllocations} freezes into a
 * Shipment's own `delivery_charge_rule_snapshot` -- a later rule or settings
 * change must never alter what a shipment already charged, and keeping the
 * whole breakdown rather than only the final figure is what lets a staff
 * member years later see exactly how it was reached.
 */
class DeliveryChargeCalculation
{
    public function __construct(
        public readonly int $pieces,
        public readonly int $boxes,
        public readonly int $actualWeightGrams,
        public readonly int $volumetricWeightGrams,
        public readonly int $chargeableWeightGrams,
        public readonly bool $usedVolumetricWeight,
        public readonly ?DeliveryChargeRule $rule,
        public readonly Money $baseCharge,
        public readonly Money $weightCharge,
        public readonly Money $boxCharge,
        public readonly Money $handlingCharge,
        public readonly Money $subtotalBeforeBounds,
        public readonly bool $freeDeliveryApplied,
        public readonly Money $finalCharge,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'pieces' => $this->pieces,
            'boxes' => $this->boxes,
            'actual_weight_grams' => $this->actualWeightGrams,
            'volumetric_weight_grams' => $this->volumetricWeightGrams,
            'chargeable_weight_grams' => $this->chargeableWeightGrams,
            'used_volumetric_weight' => $this->usedVolumetricWeight,
            'rule' => $this->rule === null ? null : [
                'id' => $this->rule->public_id,
                'weight_from_grams' => $this->rule->weight_from_grams,
                'weight_to_grams' => $this->rule->weight_to_grams,
                'area' => $this->rule->area,
                'courier_provider_id' => $this->rule->courier_provider_id,
            ],
            'base_charge' => $this->baseCharge->jsonSerialize(),
            'weight_charge' => $this->weightCharge->jsonSerialize(),
            'box_charge' => $this->boxCharge->jsonSerialize(),
            'handling_charge' => $this->handlingCharge->jsonSerialize(),
            'subtotal_before_bounds' => $this->subtotalBeforeBounds->jsonSerialize(),
            'free_delivery_applied' => $this->freeDeliveryApplied,
            'final_charge' => $this->finalCharge->jsonSerialize(),
        ];
    }
}
