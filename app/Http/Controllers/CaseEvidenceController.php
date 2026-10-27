<?php

namespace App\Http\Controllers;

use App\Domain\Cases\Actions\DiscardDraftEvidence;
use App\Domain\Cases\Actions\StoreCaseEvidence;
use App\Domain\Cases\Exceptions\CaseEvidenceRefused;
use App\Domain\Cases\Models\CaseEvidence;
use App\Domain\Cases\Queries\EvidencePayload;
use App\Domain\Order\Models\Order;
use App\Domain\Storage\ManagedStorage;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Case evidence: a Partner's unsent attachments, and the one way any of it is
 * ever served (D35).
 *
 * **Serving.** There is no URL that reaches a stored file. A link to the ERP's
 * own route, signed and short-lived, is what a screen is given; opening it checks
 * the person's right to that case afresh ({@see CaseEvidencePolicy}), so a link
 * passed to someone else, or kept past the person's own access, opens nothing.
 * The bytes come off whichever private disk holds them and are marked no-store.
 *
 * **Drafts.** A Partner attaches files while writing the request, and may take
 * one back until it is sent. What was sent is part of the case and cannot be
 * removed.
 */
class CaseEvidenceController extends Controller
{
    public function __construct(
        protected StoreCaseEvidence $uploads,
        protected DiscardDraftEvidence $discards,
        protected EvidencePayload $payload,
        protected ManagedStorage $storage,
    ) {}

    /**
     * Attach a file to a request being written.
     */
    public function store(Request $request): JsonResponse
    {
        $actor = $this->actor($request);

        $validated = $request->validate([
            'order' => ['required', 'string', 'max:40'],
            'file' => ['required', 'file'],
            'note' => ['nullable', 'string', 'max:'.StoreCaseEvidence::MAX_NOTE],
        ]);

        $account = $actor->businessAccount;

        /** @var Order|null $order */
        $order = $account === null
            ? null
            : Order::query()->where('business_account_id', $account->id)->where('public_id', $validated['order'])->first();

        abort_if($order === null, 404);

        try {
            $evidence = $this->uploads->draft($actor, $order, $request->file('file'), $validated['note'] ?? null);
        } catch (CaseEvidenceRefused $refused) {
            return response()->json(['message' => $refused->getMessage(), 'code' => $refused->errorCode], $refused->status);
        }

        return response()->json($this->payload->for($evidence), 201);
    }

    /**
     * Take back an attachment that was never sent.
     */
    public function destroy(Request $request, string $evidence): JsonResponse
    {
        $actor = $this->actor($request);
        $record = $this->find($evidence);

        Gate::forUser($actor)->authorize('delete', $record);

        try {
            $this->discards->handle($actor, $record);
        } catch (CaseEvidenceRefused $refused) {
            return response()->json(['message' => $refused->getMessage(), 'code' => $refused->errorCode], $refused->status);
        }

        return response()->json(['removed' => true]);
    }

    /**
     * Open a piece of evidence, or download it.
     */
    public function show(Request $request, string $evidence): Response
    {
        $actor = $this->actor($request);
        $record = $this->find($evidence);

        Gate::forUser($actor)->authorize('view', $record);

        $file = $record->file;
        $contents = $this->storage->diskForFile($file)->get($file->path);

        abort_if($contents === null, 404);

        $download = $request->boolean('download');

        return response($contents, 200, [
            'Content-Type' => $file->mime_type,
            'Content-Length' => (string) strlen($contents),
            'Content-Disposition' => sprintf(
                '%s; filename="%s"',
                $download ? 'attachment' : 'inline',
                addslashes($record->display_name),
            ),

            // Never cached, never stored, never indexed, never sniffed into another type.
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive, nosnippet',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox",
        ]);
    }

    protected function find(string $publicId): CaseEvidence
    {
        /** @var CaseEvidence $record */
        $record = CaseEvidence::query()->with('file')->where('public_id', $publicId)->firstOrFail();

        return $record;
    }

    protected function actor(Request $request): User
    {
        $user = $request->user();

        abort_if(! $user instanceof User, 403);

        return $user;
    }
}
