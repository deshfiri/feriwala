<?php

namespace App\Http\Controllers\Api\Mobile\V1\Kyc;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Kyc\Actions\OpenKycDraft;
use App\Domain\Kyc\Actions\SubmitKyc;
use App\Domain\Kyc\Exceptions\KycIncomplete;
use App\Domain\Kyc\KycDocumentStore;
use App\Domain\Kyc\Models\KycDocumentType;
use App\Domain\Kyc\Queries\ApplicantKycHistory;
use App\Http\Controllers\Api\Mobile\V1\Kyc\Concerns\PresentsKycForm;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Requests\Mobile\Kyc\StoreKycDocumentRequest;
use App\Http\Requests\Mobile\Kyc\SubmitKycRequest;
use App\Http\Resources\Mobile\Kyc\KycFormResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The applicant's KYC (§7). Open before activation (§5.4).
 *
 * The account always comes from the signed-in person's membership, never from
 * the URL, so there is no identifier here to substitute for somebody else's
 * (§31.3). Uploads land one at a time: a form can carry six documents, and
 * making someone re-pick all of them because the last was too large is the
 * fastest way to lose them.
 */
class KycController extends MobileController
{
    use PresentsKycForm;
    use ResolvesBusinessAccount;

    /**
     * KYC form
     *
     * Opens (or returns) the account's draft round and everything the form needs:
     * the grouped `batch` form for a round on a batch version, or the flat
     * `requirements` list for an earlier one, what has already been supplied, the
     * round's status and deadline, and the reviewer's feedback when it was sent back.
     */
    public function show(Request $request, OpenKycDraft $openDraft): KycFormResource
    {
        $account = $this->businessAccountFor($request);

        return $this->kycForm($account, $openDraft->handle($account));
    }

    /**
     * KYC history
     *
     * Every round the account has had, newest first, with each requirement's state
     * and only the feedback that was written to the applicant.
     *
     * @response array{data: list<array{id: string, status: string, status_label: string, status_tone: string, is_editable: bool, opened_at: string|null, submitted_at: string|null, reviewed_at: string|null, deadline_at: string|null, days_remaining: int|null, is_overdue: bool, was_requested: bool, instructions: string|null, requirements: list<array{key: string, name: string, instructions: string|null, is_required: bool, status: string}>, feedback: list<array{outcome: string, feedback: string, at: string}>}>}
     */
    public function history(Request $request, ApplicantKycHistory $history): JsonResponse
    {
        return new JsonResponse(['data' => $history->forAccount($this->businessAccountFor($request))]);
    }

    /**
     * Attach a document or value
     *
     * For a round on the earlier flat list: one file (`multipart/form-data`) and/or
     * one typed value for a requirement. Answers with the refreshed form. A round on
     * a batch version refuses with `422` and uses the `evidence` endpoints instead;
     * so does a round that is with a reviewer. A file that is the wrong type or too
     * large is a `422` on `file` naming the accepted formats and the limit.
     */
    public function storeDocument(
        StoreKycDocumentRequest $request,
        OpenKycDraft $openDraft,
        KycDocumentStore $store,
    ): KycFormResource {
        $account = $this->businessAccountFor($request);
        $validated = $request->validated();

        $submission = $openDraft->handle($account);

        if ($submission->kyc_batch_version_id !== null) {
            throw ValidationException::withMessages(['file' => __('kyc.batch.refused.use_new_form')]);
        }

        if (! $submission->status->isEditable()) {
            throw ValidationException::withMessages([
                'file' => 'This submission is being reviewed and cannot be changed.',
            ]);
        }

        $type = KycDocumentType::query()
            ->where('key', $validated['document_type'])
            ->forAccounts()
            ->active()
            ->firstOrFail();

        if ($request->hasFile('file')) {
            try {
                $store->store($submission, $type, $request->file('file'));
            } catch (\InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['file' => $exception->getMessage()]);
            }
        }

        if (filled($validated['value'] ?? null)) {
            $submission->fields()->updateOrCreate(
                ['kyc_document_type_id' => $type->id],
                ['value' => $validated['value']],
            );
        }

        return $this->kycForm($account, $submission->refresh());
    }

    /**
     * Submit for review
     *
     * Sends the completed round to the review team. An incomplete round is a `422`
     * on `submission` saying what is missing. A round on a batch version also needs
     * `confirm: true`. Answers with the refreshed form, whose status has moved on.
     */
    public function submit(
        SubmitKycRequest $request,
        OpenKycDraft $openDraft,
        SubmitKyc $submit,
    ): KycFormResource {
        $account = $this->businessAccountFor($request);

        $submission = $openDraft->handle($account);

        if ($submission->kyc_batch_version_id !== null && ! $request->boolean('confirm')) {
            throw ValidationException::withMessages(['confirm' => __('validation.accepted', ['attribute' => 'confirm'])]);
        }

        try {
            $submit->handle($submission);
        } catch (KycIncomplete $exception) {
            throw ValidationException::withMessages(['submission' => $exception->getMessage()]);
        }

        return $this->kycForm($account, $submission->refresh());
    }
}
