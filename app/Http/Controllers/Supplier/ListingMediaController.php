<?php

namespace App\Http\Controllers\Supplier;

use App\Domain\Supplier\Actions\ManageSupplierListingMedia;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierListingMedia;
use App\Domain\Supplier\Models\SupplierProductListing;
use App\Domain\Supplier\Models\SupplierProductListingItem;
use App\Domain\Supplier\SupplierListingMediaStore;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * A Supplier's own listing images (Supplier Bulk Product Listing batch).
 *
 * Every lookup is scoped through the authenticated Supplier's own
 * `listings()` relation, exactly as {@see ListingController} already does —
 * never a bare route-bound model.
 */
class ListingMediaController extends Controller
{
    public function store(Request $request, string $listing, ManageSupplierListingMedia $manage): RedirectResponse
    {
        $supplier = $this->supplier($request);
        $model = $this->ownListing($request, $listing);

        $validated = $request->validate([
            'file' => [
                'required',
                'file',
                'mimetypes:'.implode(',', SupplierListingMediaStore::IMAGE_TYPES),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value instanceof UploadedFile && $value->getSize() > SupplierListingMediaStore::maxBytes()) {
                        $fail(sprintf('This image is too large. The limit is %d MB.', (int) (SupplierListingMediaStore::maxBytes() / (1024 * 1024))));
                    }
                },
            ],
            'role' => ['required', Rule::in([SupplierListingMedia::ROLE_PRIMARY, SupplierListingMedia::ROLE_GALLERY])],
            'alt_text' => ['required', 'string', 'max:255'],
            'variant_id' => ['nullable', 'string'],
        ]);

        try {
            $manage->add(
                supplier: $supplier,
                listing: $model,
                file: $validated['file'],
                role: $validated['role'],
                altText: $validated['alt_text'],
                variant: $this->resolveVariant($model, $validated['variant_id'] ?? null),
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        return back()->with('success', 'Image added.');
    }

    public function update(Request $request, string $listing, string $media, ManageSupplierListingMedia $manage): RedirectResponse
    {
        $supplier = $this->supplier($request);
        $this->ownListing($request, $listing);
        $model = $this->ownMedia($listing, $media);

        $validated = $request->validate([
            'role' => ['sometimes', Rule::in([SupplierListingMedia::ROLE_PRIMARY, SupplierListingMedia::ROLE_GALLERY])],
            'alt_text' => ['sometimes', 'string', 'max:255'],
        ]);

        try {
            $manage->describe($supplier, $model, $validated);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['media' => $e->getMessage()]);
        }

        return back()->with('success', 'Image updated.');
    }

    public function reorder(Request $request, string $listing, ManageSupplierListingMedia $manage): RedirectResponse
    {
        $supplier = $this->supplier($request);
        $model = $this->ownListing($request, $listing);

        $validated = $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['string'],
        ]);

        try {
            $manage->reorder($supplier, $model, $validated['order']);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['order' => $e->getMessage()]);
        }

        return back()->with('success', 'Order updated.');
    }

    public function destroy(Request $request, string $listing, string $media, ManageSupplierListingMedia $manage): RedirectResponse
    {
        $supplier = $this->supplier($request);
        $this->ownListing($request, $listing);
        $model = $this->ownMedia($listing, $media);

        try {
            $manage->remove($supplier, $model);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['media' => $e->getMessage()]);
        }

        return back()->with('success', 'Image removed.');
    }

    protected function supplier(Request $request): Supplier
    {
        /** @var Supplier $supplier */
        $supplier = $request->user('supplier');

        return $supplier;
    }

    protected function ownListing(Request $request, string $publicId): SupplierProductListing
    {
        return $this->supplier($request)->listings()->where('public_id', $publicId)->firstOrFail();
    }

    protected function ownMedia(string $listingPublicId, string $mediaPublicId): SupplierListingMedia
    {
        return SupplierListingMedia::query()
            ->where('public_id', $mediaPublicId)
            ->whereHas('listing', fn ($query) => $query->where('public_id', $listingPublicId))
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
