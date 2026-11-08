<?php

namespace App\Http\Resources\Mobile\Supplier\Offers;

use App\Domain\Supplier\Models\SupplierOffer;
use App\Http\Resources\Mobile\MoneyResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One of the Supplier's own offers, "My Products" (D25, P13-13).
 *
 * Shows the Supplier's own base price only: the platform's rates are never
 * carried here.
 *
 * @property array{url: string, alt: string|null}|null $cover_image Set by the controller, never stored.
 *
 * @mixin SupplierOffer
 */
class SupplierOfferResource extends JsonResource
{
    public static $wrap = 'data';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** Public identifier (ULID). */
            'id' => $this->public_id,
            'reference' => $this->reference,
            'product_name' => $this->product->name,
            'product_slug' => $this->product->slug,
            /** The product's central catalog code. */
            'bpc' => $this->product->sku,
            'image' => $this->getAttribute('cover_image'),
            'variant' => $this->variant === null ? null : [
                'label' => $this->variant->label(),
                'sku' => $this->variant->sku,
            ],
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_preferred' => $this->is_preferred,
            'supplier_rate' => new MoneyResource($this->supplier_rate),
            'supply_mode' => $this->supply_mode->value,
            'supply_mode_label' => $this->supply_mode->label(),
            'available_quantity' => $this->stock?->quantity,
            'fulfilment_capacity' => $this->fulfilment_capacity,
            'lead_time_days' => $this->lead_time_days,
            'origin' => $this->origin->value,
            'origin_label' => $this->origin->label(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
