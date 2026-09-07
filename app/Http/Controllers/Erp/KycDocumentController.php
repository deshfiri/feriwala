<?php

namespace App\Http\Controllers\Erp;

use App\Domain\Kyc\KycDocumentStore;
use App\Domain\Kyc\Models\KycDocument;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * The only way a KYC document is ever served (§7.5).
 *
 * Three things happen here and nowhere else: authorisation is checked, the
 * access is recorded, and the bytes are streamed from the private disk. There is
 * no URL that reaches these files directly — the disk has `serve` disabled and
 * the model exposes no url() method, so this controller is the whole surface.
 *
 * Responses are marked no-store. A KYC document sitting in a shared browser
 * cache or a corporate proxy would undo the storage encryption entirely.
 */
class KycDocumentController extends Controller
{
    public function show(
        Request $request,
        KycDocument $document,
        KycDocumentStore $store,
    ): Response {
        Gate::authorize('view', $document);

        return $this->stream($request, $document, $store, 'view', inline: true);
    }

    public function download(
        Request $request,
        KycDocument $document,
        KycDocumentStore $store,
    ): Response {
        Gate::authorize('download', $document);

        return $this->stream($request, $document, $store, 'download', inline: false);
    }

    /**
     * @param  'view'|'download'  $action
     */
    protected function stream(
        Request $request,
        KycDocument $document,
        KycDocumentStore $store,
        string $action,
        bool $inline,
    ): Response {
        // Reading through the store is what records the access. Fetching the
        // file any other way would serve it unlogged.
        $contents = $store->read(
            document: $document,
            accessedBy: $request->user()?->id,
            action: $action,
            ip: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return response($contents, 200, [
            'Content-Type' => $document->mime_type,
            'Content-Length' => (string) strlen($contents),
            'Content-Disposition' => sprintf(
                '%s; filename="%s"',
                $inline ? 'inline' : 'attachment',
                addslashes(basename($document->original_name)),
            ),

            // Never cached, never stored, never indexed.
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive, nosnippet',
        ]);
    }
}
