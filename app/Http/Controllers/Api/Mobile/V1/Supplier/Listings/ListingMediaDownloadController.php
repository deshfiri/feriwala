<?php

namespace App\Http\Controllers\Api\Mobile\V1\Supplier\Listings;

use App\Domain\Storage\ManagedStorage;
use App\Domain\Supplier\Models\SupplierListingMedia;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Controllers\Api\Mobile\V1\Supplier\ResolvesSupplier;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a Supplier's own listing image from the disk its row records, resolved
 * through {@see ManagedStorage} so a file already moved to R2 is still found.
 *
 * Never a public URL: ownership is checked on every request.
 */
class ListingMediaDownloadController extends MobileController
{
    use ResolvesSupplier;

    /**
     * Download a listing image
     *
     * Streams the image with its stored content type. `404` for any image that
     * is not on one of this supplier's listings.
     *
     * @response string
     */
    public function show(Request $request, string $media, ManagedStorage $storage): StreamedResponse
    {
        $supplier = $this->supplierFor($request);

        $model = SupplierListingMedia::query()
            ->where('public_id', $media)
            ->whereHas('listing', fn ($query) => $query->where('supplier_id', $supplier->id))
            ->firstOrFail();

        return $storage->resolveNamedDisk($model->disk)->response($model->path, null, [
            'Content-Type' => $model->mime_type,
        ]);
    }
}
