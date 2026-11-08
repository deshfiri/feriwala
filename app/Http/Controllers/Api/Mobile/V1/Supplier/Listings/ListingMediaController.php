<?php

namespace App\Http\Controllers\Api\Mobile\V1\Supplier\Listings;

use App\Domain\Supplier\Actions\ManageSupplierListingMedia;
use App\Domain\Supplier\Models\SupplierListingMedia;
use App\Domain\Supplier\Models\SupplierProductListing;
use App\Domain\Supplier\Models\SupplierProductListingItem;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Controllers\Api\Mobile\V1\Supplier\ResolvesSupplier;
use App\Http\Requests\Mobile\Supplier\Listings\ReorderListingMediaRequest;
use App\Http\Requests\Mobile\Supplier\Listings\StoreListingMediaRequest;
use App\Http\Requests\Mobile\Supplier\Listings\UpdateListingMediaRequest;
use App\Http\Resources\Mobile\Supplier\Listings\ListingMediaResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * A Supplier's own listing images.
 *
 * The listing is always looked up through the signed-in Supplier's own
 * `listings()` relation, and an image only through that listing, so another
 * Supplier's listing or image is a `404`. Images can only change while the
 * listing is a draft or sent back for correction (`422` otherwise).
 */
class ListingMediaController extends MobileController
{
    use ResolvesSupplier;

    /**
     * Add an image
     *
     * Uploads one image (`multipart/form-data`, JPEG, PNG or WebP) to a listing,
     * or to one of its variations with `variant_id`. `422` when it is refused.
     */
    public function store(StoreListingMediaRequest $request, string $listing, ManageSupplierListingMedia $manage): JsonResponse
    {
        $model = $this->ownListing($request, $listing);
        $validated = $request->validated();

        try {
            $media = $manage->add(
                supplier: $this->supplierFor($request),
                listing: $model,
                file: $validated['file'],
                role: $validated['role'],
                altText: $validated['alt_text'],
                variant: $this->resolveVariant($model, $validated['variant_id'] ?? null),
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        return (new ListingMediaResource($media->load('item')))->response()->setStatusCode(201);
    }

    /**
     * Reorder images
     *
     * Puts the listing's own images in the order given; any left out follow in
     * their current order. Answers the images in their new order.
     *
     * @response array{data: list<ListingMediaResource>}
     */
    public function reorder(ReorderListingMediaRequest $request, string $listing, ManageSupplierListingMedia $manage): AnonymousResourceCollection
    {
        $model = $this->ownListing($request, $listing);

        try {
            $manage->reorder($this->supplierFor($request), $model, $request->validated()['order']);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['order' => $e->getMessage()]);
        }

        return ListingMediaResource::collection($model->media()->with('item')->get());
    }

    /**
     * Update an image
     *
     * Changes its `role` or `alt_text`.
     */
    public function update(UpdateListingMediaRequest $request, string $listing, string $media, ManageSupplierListingMedia $manage): ListingMediaResource
    {
        $model = $this->ownMedia($this->ownListing($request, $listing), $media);

        try {
            $updated = $manage->describe($this->supplierFor($request), $model, $request->validated());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['media' => $e->getMessage()]);
        }

        return new ListingMediaResource($updated->load('item'));
    }

    /**
     * Remove an image
     */
    public function destroy(Request $request, string $listing, string $media, ManageSupplierListingMedia $manage): Response
    {
        $model = $this->ownMedia($this->ownListing($request, $listing), $media);

        try {
            $manage->remove($this->supplierFor($request), $model);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['media' => $e->getMessage()]);
        }

        return response()->noContent();
    }

    protected function ownListing(Request $request, string $publicId): SupplierProductListing
    {
        return $this->supplierFor($request)->listings()->where('public_id', $publicId)->firstOrFail();
    }

    protected function ownMedia(SupplierProductListing $listing, string $publicId): SupplierListingMedia
    {
        return SupplierListingMedia::query()
            ->where('public_id', $publicId)
            ->where('supplier_product_listing_id', $listing->id)
            ->firstOrFail();
    }

    protected function resolveVariant(SupplierProductListing $listing, ?string $publicId): ?SupplierProductListingItem
    {
        if ($publicId === null || $publicId === '') {
            return null;
        }

        return $listing->items()->where('public_id', $publicId)->firstOrFail();
    }
}
