<?php

namespace App\Http\Resources\Mobile\Supplier\Listings;

use App\Domain\Supplier\Models\SupplierProductListing;
use Illuminate\Http\Request;

/**
 * A listing in full: the product, its variations and images, the reviewer's
 * decision and where it has been.
 *
 * Shows what the web page shows the same Supplier and no more: the reviewer's
 * `decision_note` and the public note on each status change, never staff-only
 * notes or who decided.
 *
 * @mixin SupplierProductListing
 */
class ListingDetailResource extends ListingResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'description' => $this->description,
            /** `value` of the chosen category, from `GET listings/options`. */
            'category_id' => $this->category?->public_id,
            'category_suggestion' => $this->category_suggestion,
            /** `value` of the chosen brand, from `GET listings/options`. */
            'brand_id' => $this->brand?->public_id,
            'brand_suggestion' => $this->brand_suggestion,
            'supplier_note' => $this->supplier_note,
            /** True while the Supplier may still edit it (a draft, or sent back for correction). */
            'is_editable' => $this->isEditableBySupplier(),
            /** The reviewer's overall note, once decided. */
            'decision_note' => $this->decision_note,
            'items' => ListingItemResource::collection($this->items)->resolve(),
            /** The product's own images, in order. */
            'media' => ListingMediaResource::collection($this->media)->resolve(),
            'status_history' => $this->whenLoaded('statusHistory', fn () => $this->statusHistory->map(fn ($change) => [
                'previous_status' => $change->previous_status?->label(),
                'new_status' => $change->new_status->label(),
                'reason' => $change->reason,
                'public_note' => $change->public_note,
                'changed_at' => $change->changed_at->toIso8601String(),
            ])->values()->all()),
        ];
    }
}
