<?php

namespace App\Http\Controllers\Api\Mobile\V1\Cases;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Cases\Actions\DiscardDraftEvidence;
use App\Domain\Cases\Actions\StoreCaseEvidence;
use App\Domain\Cases\Exceptions\CaseEvidenceRefused;
use App\Domain\Cases\Models\CaseEvidence;
use App\Domain\Cases\Policies\CaseEvidencePolicy;
use App\Domain\Cases\Queries\EvidencePayload;
use App\Domain\Order\Models\Order;
use App\Domain\Storage\ManagedStorage;
use App\Http\Controllers\Api\Mobile\V1\Cases\Concerns\PresentsCasesToMobile;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Case evidence from the app (D35): attaching a file to a return, exchange or
 * damage report being written, taking an unsent one back, and opening one.
 *
 * There is still no URL that reaches a stored file. Opening goes through this
 * route with the bearer token, and checks the person's right to the case on every
 * call ({@see CaseEvidencePolicy}).
 */
class CaseEvidenceController extends MobileController
{
    use PresentsCasesToMobile;
    use ResolvesBusinessAccount;

    public function __construct(
        protected StoreCaseEvidence $uploads,
        protected DiscardDraftEvidence $discards,
        protected EvidencePayload $payload,
        protected ManagedStorage $storage,
    ) {}

    /**
     * Attach a file
     *
     * Multipart: `order` (the Store Order id), `file` and an optional `note`.
     * Answers `201` with the draft; send its `id` in `evidence` when asking for
     * the return, exchange or damage report. `404` for another account's order.
     *
     * @response 201 array<string, mixed>
     */
    public function store(Request $request): JsonResponse
    {
        $actor = $this->person($request);
        $account = $this->businessAccountFor($request);

        $validated = $request->validate([
            'order' => ['required', 'string', 'max:40'],
            'file' => ['required', 'file'],
            'note' => ['nullable', 'string', 'max:'.StoreCaseEvidence::MAX_NOTE],
        ]);

        /** @var Order|null $order */
        $order = Order::query()->where('business_account_id', $account->id)->where('public_id', $validated['order'])->first();

        abort_if($order === null, 404);

        try {
            $evidence = $this->uploads->draft($actor, $order, $request->file('file'), $validated['note'] ?? null);
        } catch (CaseEvidenceRefused $refused) {
            return new JsonResponse(['message' => $this->refusal($refused), 'code' => $refused->errorCode], $refused->status);
        }

        return new JsonResponse($this->mobileEvidence($this->payload->for($evidence)), 201);
    }

    /**
     * Take back an unsent file
     *
     * Only a draft, and only the person's own. What was sent is part of the case.
     *
     * @response array{removed: bool}
     */
    public function destroy(Request $request, string $evidence): JsonResponse
    {
        $actor = $this->person($request);
        $record = $this->find($evidence);

        abort_unless(Gate::forUser($actor)->allows('delete', $record), 404);

        try {
            $this->discards->handle($actor, $record);
        } catch (CaseEvidenceRefused $refused) {
            return new JsonResponse(['message' => $this->refusal($refused), 'code' => $refused->errorCode], $refused->status);
        }

        return new JsonResponse(['removed' => true]);
    }

    /**
     * Open a file
     *
     * The file's bytes, with the bearer token. Pass `download=1` to save it rather
     * than show it. `404` when the person may not open it.
     */
    public function show(Request $request, string $evidence): Response
    {
        $actor = $this->person($request);
        $record = $this->find($evidence);

        abort_unless(Gate::forUser($actor)->allows('view', $record), 404);

        $file = $record->file;

        // Erased with its account: the record is history, there is nothing to open.
        abort_if($file === null, 404);

        $contents = $this->storage->diskForFile($file)->get($file->path);

        abort_if($contents === null, 404);

        return response($contents, 200, [
            'Content-Type' => $file->mime_type,
            'Content-Length' => (string) strlen($contents),
            'Content-Disposition' => sprintf(
                '%s; filename="%s"',
                $request->boolean('download') ? 'attachment' : 'inline',
                addslashes($record->display_name),
            ),
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    protected function find(string $publicId): CaseEvidence
    {
        /** @var CaseEvidence|null $record */
        $record = CaseEvidence::query()->with('file')->where('public_id', $publicId)->first();

        abort_if($record === null, 404);

        return $record;
    }
}
