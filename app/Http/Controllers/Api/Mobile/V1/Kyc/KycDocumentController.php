<?php

namespace App\Http\Controllers\Api\Mobile\V1\Kyc;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Kyc\KycDocumentStore;
use App\Domain\Kyc\Models\KycDocument;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * The applicant's own uploaded KYC files (§7.5).
 *
 * Found among this account's submissions before the identifier is used, so
 * another account's document is a `404` and never a file. Authorisation then
 * runs through the same policy as the web, the access is recorded by reading
 * through the store, and the bytes are streamed from the private disk — there is
 * no URL that reaches them directly. Responses are never cached.
 */
class KycDocumentController extends MobileController
{
    use ResolvesBusinessAccount;

    /**
     * View a document
     *
     * Streams one of the account's own uploaded files for display (`Content-Disposition: inline`).
     * The view is recorded. `404` when the document is not this account's.
     *
     * @response binary
     */
    public function show(Request $request, string $document, KycDocumentStore $store): Response
    {
        $record = $this->documentFor($request, $document);

        Gate::authorize('view', $record);

        return $this->stream($request, $record, $store, 'view', inline: true);
    }

    /**
     * Download a document
     *
     * The same file as an attachment (`Content-Disposition: attachment`). The download is
     * recorded. `404` when the document is not this account's.
     *
     * @response binary
     */
    public function download(Request $request, string $document, KycDocumentStore $store): Response
    {
        $record = $this->documentFor($request, $document);

        Gate::authorize('download', $record);

        return $this->stream($request, $record, $store, 'download', inline: false);
    }

    protected function documentFor(Request $request, string $document): KycDocument
    {
        $account = $this->businessAccountFor($request);

        return KycDocument::query()
            ->where('public_id', $document)
            ->whereHas('submission', fn ($submissions) => $submissions->where('business_account_id', $account->id))
            ->firstOrFail();
    }

    /**
     * @param  'view'|'download'  $action
     */
    protected function stream(Request $request, KycDocument $document, KycDocumentStore $store, string $action, bool $inline): Response
    {
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
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive, nosnippet',
        ]);
    }
}
