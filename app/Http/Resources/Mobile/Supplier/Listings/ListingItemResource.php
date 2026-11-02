<?php

namespace App\Http\Resources\Mobile\Supplier\Listings;

use App\Domain\Supplier\Models\SupplierProductListingItem;
use App\Http\Resources\Mobile\MoneyResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One proposed variation of a listing.
 *
 * Only what the Supplier proposed and what the reviewer decided about it. The
 * platform's own rates are never part of this.
 *
 * @mixin SupplierProductListingItem
 */
class ListingItemResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** Opaque public identifier (ULID). */
            'id' => $this->public_id,
            'variant_label' => $this->variant_label,
            'supplier_sku' => $this->supplier_sku,
            /** What the Supplier asks to be paid per unit. */
            'supplier_rate' => new MoneyResource($this->supplier_rate),
            'available_quantity' => $this->available_quantity,
            'minimum_supply_quantity' => $this->minimum_supply_quantity,
            'lead_time_days' => $this->lead_time_days,
            'warranty' => $this->warranty,
            'return_conditions' => $this->return_conditions,
            'supply_mode' => $this->supply_mode->value,
            'supply_mode_label' => $this->supply_mode->label(),
            'fulfilment_capacity' => $this->fulfilment_capacity,
            'expected_availability_at' => $this->expected_availability_at?->toIso8601String(),
            /** Logistics figures the Supplier proposed; a reviewer confirms or corrects them. */
            'proposed_logistics' => $this->proposedLogistics(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_tone' => $this->status->tone(),
            /** The reviewer's note on this variation, once decided. */
            'decision_note' => $this->decision_note,
            /** `value` of the attribute values chosen for this variation. Present when loaded. */
            'attribute_value_ids' => $this->whenLoaded('attributeValues', fn () => $this->attributeValues->pluck('public_id')->all()),
            /** Images specific to this variation. Present when loaded. */
            'media' => $this->whenLoaded('variantMedia', fn () => ListingMediaResource::collection($this->variantMedia)->resolve()),
        ];
    }
}
