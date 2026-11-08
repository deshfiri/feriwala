<?php

namespace App\Http\Resources\Mobile\Supplier\ListingLots;

use App\Domain\Supplier\Models\SupplierProductListingLot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One of the Supplier's listing batches, as the list shows it.
 *
 * @mixin SupplierProductListingLot
 */
class ListingLotResource extends JsonResource
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
            'title' => $this->title,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_tone' => $this->status->tone(),
            /** How many product entries the batch holds. Present on the list. */
            'item_count' => $this->whenCounted('items'),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
