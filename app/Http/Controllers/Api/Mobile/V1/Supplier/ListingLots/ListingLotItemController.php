<?php

namespace App\Http\Controllers\Api\Mobile\V1\Supplier\ListingLots;

use App\Domain\Supplier\Actions\RemoveSupplierListingLotItem;
use App\Domain\Supplier\Actions\SaveSupplierListingDraft;
use App\Domain\Supplier\Models\SupplierProductListing;
use App\Domain\Supplier\Models\SupplierProductListingLot;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Controllers\Api\Mobile\V1\Supplier\ResolvesSupplier;
use App\Http\Requests\Mobile\Supplier\ListingLots\SaveLotEntryRequest;
use App\Http\Resources\Mobile\Supplier\Listings\ListingDetailResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * One product entry inside a Supplier's listing batch.
 *
 * An entry is an ordinary listing row, saved through the same action as a
 * single listing, so every field rule is enforced in one place. Only the
 * Supplier's own `supplier_rate` is accepted, never a platform rate. Images go
 * through the listing media endpoints using the entry's `id`. Another
 * Supplier's batch or entry is a `404`.
 */
class ListingLotItemController extends MobileController
{
    use ResolvesSupplier;

    /**
     * Add a product entry
     *
     * Adds a product and its variations to a draft batch. `422` when the batch
     * can no longer take entries or a field is invalid.
     */
    public function store(SaveLotEntryRequest $request, string $lot, SaveSupplierListingDraft $save): JsonResponse
    {
        $model = $this->ownLot($request, $lot);

        [$attributes, $items] = $request->draft();

        try {
            $entry = $save->handle($this->supplierFor($request), $attributes, $items, lot: $model);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['entry' => $e->getMessage()]);
        }

        return (new ListingDetailResource($this->detailed($entry, $model)))->response()->setStatusCode(201);
    }

    /**
     * Update a product entry
     *
     * Rewrites an entry while it is a draft or sent back for correction. `422`
     * when it can no longer be edited.
     */
    public function update(SaveLotEntryRequest $request, string $lot, string $item, SaveSupplierListingDraft $save): ListingDetailResource
    {
        $lotModel = $this->ownLot($request, $lot);
        $entry = $this->ownEntry($lotModel, $item);

        [$attributes, $items] = $request->draft();

        try {
            $save->handle($this->supplierFor($request), $attributes, $items, listing: $entry, lot: $lotModel);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['entry' => $e->getMessage()]);
        }

        return new ListingDetailResource($this->detailed($entry, $lotModel));
    }

    /**
     * Remove a product entry
     *
     * Archives the entry; it is never deleted. Answers the entry as archived.
     * `422` when it can no longer be removed.
     */
    public function destroy(Request $request, string $lot, string $item, RemoveSupplierListingLotItem $remove): ListingDetailResource
    {
        $lotModel = $this->ownLot($request, $lot);
        $entry = $this->ownEntry($lotModel, $item);

        try {
            $remove->handle($this->supplierFor($request), $entry);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['entry' => $e->getMessage()]);
        }

        return new ListingDetailResource($this->detailed($entry, $lotModel));
    }

    protected function ownLot(Request $request, string $publicId): SupplierProductListingLot
    {
        return $this->supplierFor($request)->lots()->where('public_id', $publicId)->firstOrFail();
    }

    protected function ownEntry(SupplierProductListingLot $lot, string $publicId): SupplierProductListing
    {
        return $lot->items()->where('public_id', $publicId)->firstOrFail();
    }

    protected function detailed(SupplierProductListing $entry, SupplierProductListingLot $lot): SupplierProductListing
    {
        $entry->refresh()->load([
            'category',
            'brand',
            'media',
            'items.attributeValues',
            'items.variantMedia.item',
            'statusHistory' => fn ($query) => $query->orderByDesc('id'),
        ]);

        return $entry->setRelation('lot', $lot);
    }
}
