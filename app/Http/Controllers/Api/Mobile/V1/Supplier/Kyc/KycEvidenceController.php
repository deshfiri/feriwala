<?php

namespace App\Http\Controllers\Api\Mobile\V1\Supplier\Kyc;

use App\Domain\Kyc\Batch\KycEvidenceRefused;
use App\Domain\Kyc\Batch\KycRound;
use App\Domain\Kyc\Batch\RecordKycEvidence;
use App\Domain\Kyc\Batch\SupplierKycRound;
use App\Domain\Supplier\Actions\OpenSupplierKycRound;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Queries\SupplierKycOverview;
use App\Domain\Supplier\SupplierKycDocumentStore;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Requests\Mobile\Supplier\Kyc\KycEvidenceFileRequest;
use App\Http\Requests\Mobile\Supplier\Kyc\KycEvidenceOptionRequest;
use App\Http\Requests\Mobile\Supplier\Kyc\KycEvidenceSlotRequest;
use App\Http\Requests\Mobile\Supplier\Kyc\KycEvidenceValueRequest;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * A Supplier's evidence endpoints for a round on a KYC batch (D30).
 *
 * Every rule (what a slot accepts, what may still change) lives in
 * {@see RecordKycEvidence}; a refusal comes back as `422` on the field named
 * in each method. The round always comes from the signed-in supplier, never
 * from the URL. Each call returns the refreshed KYC payload, the same `data`
 * as `GET /supplier/kyc`.
 */
class KycEvidenceController extends MobileController
{
    public function __construct(
        protected OpenSupplierKycRound $openRound,
        protected SupplierKycDocumentStore $store,
        protected SupplierKycOverview $overview,
    ) {}

    /**
     * Upload evidence for a slot
     *
     * Puts a file (`multipart/form-data`, up to 10 MB) into a requirement's
     * slot, which also chooses that option within its group. A selfie may be
     * flagged `captured_live`. `422 file` when the slot refuses it.
     *
     * @response array{data: array<string, mixed>}
     */
    public function file(KycEvidenceFileRequest $request, RecordKycEvidence $evidence): JsonResponse
    {
        $validated = $request->validated();
        $live = $request->boolean('captured_live');

        // Only the frame's size and which camera took it.
        $meta = $live
            ? array_filter([
                'width' => $validated['capture_meta']['width'] ?? null,
                'height' => $validated['capture_meta']['height'] ?? null,
                'facing' => $validated['capture_meta']['facing'] ?? null,
            ])
            : null;

        return $this->attempt($request, 'file', fn () => $evidence->file(
            $this->round($request),
            $validated['requirement'],
            $validated['slot'],
            $request->file('file'),
            $live,
            $meta,
        ));
    }

    /**
     * Record a typed value for a slot
     *
     * For a slot that takes a typed value such as an ID number. `422 value`
     * when the slot refuses it.
     *
     * @response array{data: array<string, mixed>}
     */
    public function value(KycEvidenceValueRequest $request, RecordKycEvidence $evidence): JsonResponse
    {
        $validated = $request->validated();

        return $this->attempt($request, 'value', fn () => $evidence->value(
            $this->round($request),
            $validated['requirement'],
            $validated['slot'],
            $validated['value'],
        ));
    }

    /**
     * Remove evidence from a slot
     *
     * Clears a slot of an editable round. `422 slot` when it cannot be removed
     * (for example it was already accepted).
     *
     * @response array{data: array<string, mixed>}
     */
    public function remove(KycEvidenceSlotRequest $request, RecordKycEvidence $evidence): JsonResponse
    {
        $validated = $request->validated();

        return $this->attempt($request, 'slot', fn () => $evidence->remove(
            $this->round($request),
            $validated['requirement'],
            $validated['slot'],
        ));
    }

    /**
     * Choose an option in a group
     *
     * Picks one option of a group that offers a choice. `422 option` when the
     * group is full or the option is locked.
     *
     * @response array{data: array<string, mixed>}
     */
    public function choose(KycEvidenceOptionRequest $request, RecordKycEvidence $evidence): JsonResponse
    {
        $validated = $request->validated();

        return $this->attempt($request, 'option', fn () => $evidence->choose(
            $this->round($request),
            $validated['group'],
            $validated['requirement'],
        ));
    }

    /**
     * Deselect an option in a group
     *
     * Walks away from a chosen option, discarding its draft evidence. `422
     * option` when it is locked behind a decision.
     *
     * @response array{data: array<string, mixed>}
     */
    public function deselect(KycEvidenceOptionRequest $request, RecordKycEvidence $evidence): JsonResponse
    {
        $validated = $request->validated();

        return $this->attempt($request, 'option', fn () => $evidence->deselect(
            $this->round($request),
            $validated['group'],
            $validated['requirement'],
        ));
    }

    protected function round(Request $request): KycRound
    {
        /** @var Supplier $supplier */
        $supplier = $request->user();

        return new SupplierKycRound($this->openRound->handle($supplier), $this->store);
    }

    /**
     * @param  Closure(): void  $change
     */
    protected function attempt(Request $request, string $field, Closure $change): JsonResponse
    {
        try {
            $change();
        } catch (KycEvidenceRefused $refused) {
            throw ValidationException::withMessages([$field => $refused->getMessage()]);
        }

        /** @var Supplier $supplier */
        $supplier = $request->user();

        return new JsonResponse(['data' => $this->overview->for($supplier)]);
    }
}