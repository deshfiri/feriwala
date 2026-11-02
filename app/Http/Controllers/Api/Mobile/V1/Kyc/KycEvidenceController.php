<?php

namespace App\Http\Controllers\Api\Mobile\V1\Kyc;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Kyc\Actions\OpenKycDraft;
use App\Domain\Kyc\Batch\KycEvidenceRefused;
use App\Domain\Kyc\Batch\KycRound;
use App\Domain\Kyc\Batch\PartnerKycRound;
use App\Domain\Kyc\Batch\RecordKycEvidence;
use App\Domain\Kyc\KycDocumentStore;
use App\Http\Controllers\Api\Mobile\V1\Kyc\Concerns\PresentsKycForm;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Requests\Mobile\Kyc\KycEvidenceFileRequest;
use App\Http\Requests\Mobile\Kyc\KycEvidenceOptionRequest;
use App\Http\Requests\Mobile\Kyc\KycEvidenceSlotRequest;
use App\Http\Requests\Mobile\Kyc\KycEvidenceValueRequest;
use App\Http\Resources\Mobile\Kyc\KycFormResource;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The applicant's evidence for a round on a KYC batch (D30).
 *
 * The round is the signed-in person's own account's open draft, resolved from
 * their membership and never from the request, so there is no identifier here
 * to substitute for somebody else's round (§31.3). Every rule — what a slot
 * accepts, what may still change — is in {@see RecordKycEvidence}; a refusal is a
 * `422` on the field named in each endpoint, carrying a message the applicant can
 * act on. Each change answers with the refreshed form.
 */
class KycEvidenceController extends MobileController
{
    use PresentsKycForm;
    use ResolvesBusinessAccount;

    public function __construct(
        protected OpenKycDraft $openDraft,
        protected KycDocumentStore $store,
    ) {}

    /**
     * Upload a file into a slot
     *
     * `multipart/form-data`. A live selfie is sent with `captured_live=1` and the
     * frame's size and camera in `capture_meta`. Refused with `422` on `file` when
     * the slot does not take files, wants the camera, or the file is the wrong
     * format or too large.
     */
    public function file(KycEvidenceFileRequest $request, RecordKycEvidence $evidence): KycFormResource
    {
        $validated = $request->validated();
        $live = $request->boolean('captured_live');

        // Only the frame's size and which camera took it — nothing about the face,
        // no device identifiers.
        $meta = $live
            ? array_filter([
                'width' => $validated['capture_meta']['width'] ?? null,
                'height' => $validated['capture_meta']['height'] ?? null,
                'facing' => $validated['capture_meta']['facing'] ?? null,
            ])
            : null;

        return $this->attempt($request, 'file', fn (KycRound $round) => $evidence->file(
            $round,
            $validated['requirement'],
            $validated['slot'],
            $request->file('file'),
            $live,
            $meta,
        ));
    }

    /**
     * Enter a value into a slot
     *
     * A typed value such as an ID number. `422` on `value` when the slot does not take
     * a typed value or the round cannot change.
     */
    public function value(KycEvidenceValueRequest $request, RecordKycEvidence $evidence): KycFormResource
    {
        $validated = $request->validated();

        return $this->attempt($request, 'value', fn (KycRound $round) => $evidence->value(
            $round,
            $validated['requirement'],
            $validated['slot'],
            $validated['value'],
        ));
    }

    /**
     * Remove what a slot holds
     *
     * Takes back a draft file or value. `422` on `slot` when it is locked behind a
     * decision.
     */
    public function remove(KycEvidenceSlotRequest $request, RecordKycEvidence $evidence): KycFormResource
    {
        $validated = $request->validated();

        return $this->attempt($request, 'slot', fn (KycRound $round) => $evidence->remove(
            $round,
            $validated['requirement'],
            $validated['slot'],
        ));
    }

    /**
     * Choose an option
     *
     * Picks one option of an "any one of" group. `422` on `option` when the choice is
     * locked.
     */
    public function choose(KycEvidenceOptionRequest $request, RecordKycEvidence $evidence): KycFormResource
    {
        $validated = $request->validated();

        return $this->attempt($request, 'option', fn (KycRound $round) => $evidence->choose(
            $round,
            $validated['group'],
            $validated['requirement'],
        ));
    }

    /**
     * Walk away from an option
     *
     * Clears an unsent option so another can be chosen. `422` on `option` when it
     * holds evidence behind a decision.
     */
    public function deselect(KycEvidenceOptionRequest $request, RecordKycEvidence $evidence): KycFormResource
    {
        $validated = $request->validated();

        return $this->attempt($request, 'option', fn (KycRound $round) => $evidence->deselect(
            $round,
            $validated['group'],
            $validated['requirement'],
        ));
    }

    /**
     * @param  Closure(KycRound): void  $change
     */
    private function attempt(Request $request, string $field, Closure $change): KycFormResource
    {
        $account = $this->businessAccountFor($request);
        $submission = $this->openDraft->handle($account);

        try {
            $change(new PartnerKycRound($submission, $this->store));
        } catch (KycEvidenceRefused $refused) {
            throw ValidationException::withMessages([$field => $refused->getMessage()]);
        }

        return $this->kycForm($account, $submission->refresh());
    }
}
