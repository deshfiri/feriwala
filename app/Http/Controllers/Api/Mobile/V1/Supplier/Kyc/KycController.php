<?php

namespace App\Http\Controllers\Api\Mobile\V1\Supplier\Kyc;

use App\Domain\Supplier\Actions\OpenSupplierKycRound;
use App\Domain\Supplier\Actions\SubmitSupplierKyc;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierKycRequirement;
use App\Domain\Supplier\Queries\SupplierKycOverview;
use App\Domain\Supplier\SupplierKycDocumentStore;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Requests\Mobile\Supplier\Kyc\StoreKycDocumentRequest;
use App\Http\Requests\Mobile\Supplier\Kyc\SubmitKycRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * A Supplier's own KYC (D25, P13-7). Open to a supplier in any status: a Draft
 * or correction-required supplier is exactly who needs it.
 *
 * Every lookup goes through the signed-in Supplier's own round; there is no
 * round identifier in any URL to substitute for another supplier's.
 *
 * Every endpoint here and in the evidence endpoints answers with the same
 * `data` payload as `GET /supplier/kyc`, refreshed:
 * `{batch, supplier_status, round, history, requirements}`. `batch` is the
 * grouped form (groups, options, slots, progress) for a round on a KYC batch;
 * it is `null` for a round on the earlier flat list, which uses `requirements`
 * and `POST /supplier/kyc/documents`. Typed values appear masked and storage
 * paths never appear.
 */
class KycController extends MobileController
{
    /**
     * KYC round
     *
     * Opens the supplier's KYC round if there is none, and returns what is
     * asked, what has been given, and previous rounds with their decisions.
     *
     * @response array{data: array{batch: array<string, mixed>|null, supplier_status: string, round: array{id: string, status: string, status_label: string, is_editable: bool, decision_note: string|null, submitted_at: string|null, documents: list<array{id: string, type: string, label: string, original_name: string, size_bytes: int, mime_type: string}>}, history: list<array{id: string, submitted_at: string|null, status: string, status_label: string, reviewed_at: string|null, decision_note: string|null}>, requirements: list<array<string, mixed>>}}
     */
    public function show(Request $request, SupplierKycOverview $overview): JsonResponse
    {
        /** @var Supplier $supplier */
        $supplier = $request->user();

        return new JsonResponse(['data' => $overview->for($supplier)]);
    }

    /**
     * Save a KYC item (flat list)
     *
     * For a round on the earlier flat list only: uploads a file
     * (`multipart/form-data`) and/or a typed value for one requirement. A round
     * on a batch answers `422 file`; use the evidence endpoints instead. Also
     * `422` when the round is under review, the item does not take that kind of
     * input, or nothing was provided.
     *
     * @response array{data: array<string, mixed>}
     */
    public function storeDocument(StoreKycDocumentRequest $request, OpenSupplierKycRound $openRound, SupplierKycDocumentStore $store, SupplierKycOverview $overview): JsonResponse
    {
        /** @var Supplier $supplier */
        $supplier = $request->user();

        $round = $openRound->handle($supplier);

        if ($round->kyc_batch_version_id !== null) {
            throw ValidationException::withMessages(['file' => __('kyc.batch.refused.use_new_form')]);
        }

        $validated = $request->validated();

        if (! $round->status->isEditable()) {
            throw ValidationException::withMessages([
                'file' => 'This submission is being reviewed and cannot be changed.',
            ]);
        }

        /** @var SupplierKycRequirement $requirement */
        $requirement = $round->requirements()->where('key', $validated['document_type'])->firstOrFail();

        if (! $request->hasFile('file') && blank($validated['value'] ?? null)) {
            throw ValidationException::withMessages([
                $requirement->requires_file ? 'file' : 'value' => 'Provide a file or a value.',
            ]);
        }

        if ($request->hasFile('file')) {
            if (! $requirement->requires_file) {
                throw ValidationException::withMessages(['file' => 'This item takes a value, not a file.']);
            }

            try {
                $store->store($round, $requirement, $request->file('file'));
            } catch (InvalidArgumentException $e) {
                throw ValidationException::withMessages(['file' => $e->getMessage()]);
            }
        }

        if (filled($validated['value'] ?? null)) {
            if (! $requirement->requires_value) {
                throw ValidationException::withMessages(['value' => 'This item takes a file, not a value.']);
            }

            $round->fields()->updateOrCreate(
                ['key' => $requirement->key],
                ['value' => $validated['value']],
            );
        }

        return new JsonResponse(['data' => $overview->for($supplier)]);
    }

    /**
     * Submit KYC for review
     *
     * Sends the round to staff. On a batch round `confirm` must be `true`
     * (`422 confirm` otherwise). Responds `422 submission` when something is
     * still required, email or mobile is not yet verified, or the round is not
     * editable. The supplier moves to review; the refreshed round is returned.
     *
     * @response array{data: array<string, mixed>}
     */
    public function submit(SubmitKycRequest $request, SubmitSupplierKyc $submit, OpenSupplierKycRound $openRound, SupplierKycOverview $overview): JsonResponse
    {
        /** @var Supplier $supplier */
        $supplier = $request->user();

        if ($openRound->handle($supplier)->kyc_batch_version_id !== null && ! $request->boolean('confirm')) {
            throw ValidationException::withMessages(['confirm' => __('validation.accepted', ['attribute' => 'confirm'])]);
        }

        try {
            $submit->handle($supplier);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['submission' => $e->getMessage()]);
        }

        return new JsonResponse(['data' => $overview->for($supplier->refresh())]);
    }
}