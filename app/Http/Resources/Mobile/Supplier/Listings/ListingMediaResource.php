<?php

namespace App\Http\Resources\Mobile\Supplier\Listings;

use App\Domain\Supplier\Models\SupplierListingMedia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One image on a listing.
 *
 * Never a public URL: a listing is a proposal, so `download_url` is a route that
 * checks ownership on every request and needs the Supplier's bearer token.
 *
 * @mixin SupplierListingMedia
 */
class ListingMediaResource extends JsonResource
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
            /** `primary` or `gallery`. */
            'role' => $this->role,
            'alt_text' => $this->alt_text,
            'position' => $this->position,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'width' => $this->width,
            'height' => $this->height,
            /** `id` of the variation this image belongs to, or null for an image of the product itself. */
            'variant_id' => $this->whenLoaded('item', fn () => $this->item?->public_id),
            /** Fetch with `Authorization: Bearer`; answers the image bytes. */
            'download_url' => route('mobile.v1.supplier.listings.media.download', $this->public_id),
        ];
    }
}
