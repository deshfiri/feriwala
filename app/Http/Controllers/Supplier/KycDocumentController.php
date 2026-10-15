<?php

namespace App\Http\Controllers\Supplier;

use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierKycDocument;
use App\Domain\Supplier\SupplierKycDocumentStore;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A Supplier looking at a file they themselves uploaded (D25).
 *
 * Only their own: the document must belong to a round of the signed-in Supplier,
 * or it is not found — never "forbidden", so the existence of another Supplier's
 * file is not confirmed. The bytes are streamed from the private disk (there is no
 * URL to the file itself) and marked no-store, like every KYC document (§7.5).
 *
 * It exists so a draft can be previewed and checked before it is sent. A staff
 * member opening a Supplier's document is the access §7.5 polices, and that is
 * recorded where staff open it; a Supplier reading their own is not.
 */
class KycDocumentController extends Controller
{
    public function show(Request $request, SupplierKycDocument $document, SupplierKycDocumentStore $store): Response
    {
        /** @var Supplier $supplier */
        $supplier = $request->user('supplier');

        abort_unless($document->submission()->where('supplier_id', $supplier->id)->exists(), 404);

        $contents = $store->read($document);

        return response($contents, 200, [
            'Content-Type' => $document->mime_type,
            'Content-Length' => (string) strlen($contents),
            'Content-Disposition' => sprintf('inline; filename="%s"', addslashes(basename($document->original_name))),
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive, nosnippet',
        ]);
    }
}
