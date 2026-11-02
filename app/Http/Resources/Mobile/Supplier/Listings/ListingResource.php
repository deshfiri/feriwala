<?php

namespace App\Http\Resources\Mobile\Supplier\Listings;

use App\Domain\Supplier\Models\SupplierProductListing;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One of the Supplier's Product Listing Requests, as the list shows it.
 *
 * @mixin SupplierProductListing
 */
class ListingResource extends JsonResource
{
    public static $wrap = 'data';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** Opaque public identifier (ULID). */
            'id' => $this->public_id,
            'reference' => $this->reference,
            'product_name' => $this->product_name,
            /** `id` of the batch this entry belongs to, or null for a stand-alone listing. A batch entry is submitted with its batch. */
            'lot_id' => $this->whenLoaded('lot', fn () => $this->lot?->public_id),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_tone' => $this->status->tone(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
