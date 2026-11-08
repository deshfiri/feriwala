<?php

namespace App\Http\Resources\Mobile\Supplier\ListingLots;

use App\Domain\Supplier\Enums\LotStatus;
use App\Domain\Supplier\Models\SupplierProductListingLot;
use App\Http\Resources\Mobile\Supplier\Listings\ListingDetailResource;
use Illuminate\Http\Request;

/**
 * A batch in full: every product entry with its variations and images, and where
 * the batch has been.
 *
 * @mixin SupplierProductListingLot
 */
class ListingLotDetailResource extends ListingLotResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            /** True while entries may still be added and the batch submitted. */
            'is_draft' => $this->status === LotStatus::Draft,
            /** The batch's product entries, each shaped as a listing. */
            'entries' => ListingDetailResource::collection($this->items)->resolve(),
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
