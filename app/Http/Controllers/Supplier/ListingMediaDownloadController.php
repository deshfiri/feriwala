<?php

namespace App\Http\Controllers\Supplier;

use App\Domain\Storage\ManagedStorage;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierListingMedia;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a Supplier's own listing image back from wherever it actually
 * lives -- its own stored `disk` column, resolved through
 * {@see ManagedStorage} so a file already migrated to Cloudflare R2 is
 * still found there (Supplier Bulk Product Listing batch; beta-critical
 * batch).
 *
 * Never a public URL: a listing is a pre-approval proposal, and this route
 * is the only way its files are ever read, so ownership is checked on every
 * request rather than once at upload time.
 */
class ListingMediaDownloadController extends Controller
{
    public function show(Request $request, string $media, ManagedStorage $storage): StreamedResponse
    {
        /** @var Supplier $supplier */
        $supplier = $request->user('supplier');

        $model = SupplierListingMedia::query()
            ->where('public_id', $media)
            ->whereHas('listing', fn ($query) => $query->where('supplier_id', $supplier->id))
            ->firstOrFail();

        return $storage->resolveNamedDisk($model->disk)->response($model->path, null, [
            'Content-Type' => $model->mime_type,
        ]);
    }
}
