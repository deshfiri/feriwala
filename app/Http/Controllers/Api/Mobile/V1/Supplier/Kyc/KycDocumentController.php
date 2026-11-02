<?php

namespace App\Http\Controllers\Api\Mobile\V1\Supplier\Kyc;

use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierKycDocument;
use App\Domain\Supplier\SupplierKycDocumentStore;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A Supplier looking at a file they themselves uploaded (D25).
 *
 * Only their own: the document must belong to a round of the signed-in
 * Supplier, or it is not found, so another supplier's file is never confirmed
 * to exist. Streamed from the private disk (there is no URL to the file itself)
 * and marked no-store, like every KYC document (§7.5), as on the web.
 */
class KycDocumentController extends MobileController
{
    /**
     * Download an uploaded KYC file
     *
     * Streams the supplier's own uploaded file, with its original content type,
     * to preview a draft before sending it. `404` for any document that is not
     * this supplier's. Not cacheable.
     *
     * @response string
     */
    public function show(Request $request, string $document, SupplierKycDocumentStore $store): Response
    {
        /** @var Supplier $supplier */
        $supplier = $request->user();

        $found = SupplierKycDocument::query()
            ->where('public_id', $document)
            ->whereHas('submission', fn ($query) => $query->where('supplier_id', $supplier->id))
            ->firstOrFail();

        $contents = $store->read($found);

        return response($contents, 200, [
            'Content-Type' => $found->mime_type,
            'Content-Length' => (string) strlen($contents),
            'Content-Disposition' => sprintf('inline; filename="%s"', addslashes(basename($found->original_name))),
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive, nosnippet',
        ]);
    }
}