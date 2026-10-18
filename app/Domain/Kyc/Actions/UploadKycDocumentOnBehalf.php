<?php

namespace App\Domain\Kyc\Actions;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Kyc\Batch\KycEvidenceRefused;
use App\Domain\Kyc\Batch\KycReviewRefused;
use App\Domain\Kyc\Batch\PartnerKycRound;
use App\Domain\Kyc\Batch\RecordKycEvidence;
use App\Domain\Kyc\Batch\RecordKycVerdict;
use App\Domain\Kyc\Batch\SupplierKycRound;
use App\Domain\Kyc\Data\KycDecision;
use App\Domain\Kyc\Exceptions\KycIncomplete;
use App\Domain\Kyc\KycDocumentStore;
use App\Domain\Kyc\Models\KycDocument;
use App\Domain\Kyc\Models\KycDocumentType;
use App\Domain\Kyc\Models\KycSubmission;
use App\Domain\Supplier\Actions\ApproveSupplierKyc;
use App\Domain\Supplier\Actions\OpenSupplierKycRound;
use App\Domain\Supplier\Actions\SubmitSupplierKyc;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierKycDocument;
use App\Domain\Supplier\Models\SupplierKycSubmission;
use App\Domain\Supplier\SupplierKycDocumentStore;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

/**
 * A member of staff puts a KYC document on a partner's or a Supplier's round
 * on the applicant's behalf.
 *
 * It works on the **same round the applicant would be filling in**, through the
 * same stores and the same evidence rules — a private disk, a random name,
 * encryption at rest, the round's own size and format limits — so nothing here
 * is a second, looser way to get a file into KYC. What differs is provenance:
 * the file carries the member of staff who added it, the audit log records who,
 * why and what, and a reviewer can tell it did not come from the applicant.
 *
 * Only a round that is still being filled in (a draft, or one sent back for
 * correction) takes uploads. One awaiting a decision, or already decided, is
 * evidence somebody is relying on; changing it underneath them would make the
 * review meaningless, exactly as it would for the applicant.
 *
 * The upload does not decide anything. Sending the round for review is a
 * separate, explicit choice (`$submit`), and approving it stays with the
 * reviewer — unless staff with the approval permission ask to verify on the
 * spot (`$verify`), which sends, accepts and approves the round as that
 * reviewer, recorded like any other review.
 *
 * A target is either `type:<key>` (a document of the round's flat list) or
 * `slot:<requirement>:<slot>` (a slot of a batch round).
 */
class UploadKycDocumentOnBehalf
{
    public function __construct(
        protected OpenKycDraft $openPartnerRound,
        protected OpenSupplierKycRound $openSupplierRound,
        protected KycDocumentStore $partnerStore,
        protected SupplierKycDocumentStore $supplierStore,
        protected RecordKycEvidence $evidence,
        protected SubmitKyc $submitPartner,
        protected SubmitSupplierKyc $submitSupplier,
        protected RecordAuditLog $audit,
        protected RecordKycVerdict $verdicts,
        protected ReviewKyc $reviewPartner,
        protected ApproveSupplierKyc $approveSupplier,
    ) {}

    /**
     * What can be uploaded to this partner's round right now. Opens the round
     * the applicant would see if there is none, which is the same thing their
     * own KYC screen does the first time they arrive.
     *
     * @return array<int, array{value: string, label: string}>
     *
     * @throws InvalidArgumentException when the round cannot take uploads
     */
    public function partnerTargets(BusinessAccount $account): array
    {
        return $this->targets($this->partnerRound($account));
    }

    /**
     * @return array<int, array{value: string, label: string}>
     *
     * @throws InvalidArgumentException
     */
    public function supplierTargets(Supplier $supplier): array
    {
        return $this->targets($this->supplierRound($supplier));
    }

    /**
     * @throws InvalidArgumentException
     * @throws KycIncomplete
     */
    public function forPartner(BusinessAccount $account, User $actor, string $target, UploadedFile $file, string $reason, bool $submit = false, bool $verify = false): KycDocument
    {
        $reason = $this->reason($reason);
        $submission = $this->partnerRound($account);

        $this->guardTarget($submission, $target);

        [$kind, $requirement, $slot] = $this->parseTarget($target);

        try {
            if ($kind === 'type') {
                $type = KycDocumentType::query()
                    ->where('key', $requirement)
                    ->forAccounts()
                    ->active()
                    ->first();

                if ($type === null) {
                    throw new InvalidArgumentException(__('kyc.batch.refused.unknown_slot'));
                }

                $document = $this->partnerStore->store($submission, $type, $file);
            } else {
                $this->evidence->file(new PartnerKycRound($submission, $this->partnerStore), $requirement, $slot, $file);

                $document = $submission->documents()->latest('id')->firstOrFail();
            }
        } catch (KycEvidenceRefused $refused) {
            throw new InvalidArgumentException($refused->getMessage());
        }

        $document->forceFill(['uploaded_by_staff_id' => $actor->id])->save();

        $this->record($actor, $submission, $document, $target, $reason, 'kyc', $account->id);

        if ($verify) {
            $this->verifyPartner($submission, $actor, $reason);
        } elseif ($submit) {
            $this->submitPartner->handle($submission->refresh());
        }

        return $document;
    }

    /**
     * @throws InvalidArgumentException
     */
    public function forSupplier(Supplier $supplier, User $actor, string $target, UploadedFile $file, string $reason, bool $submit = false, bool $verify = false): SupplierKycDocument
    {
        $reason = $this->reason($reason);
        $submission = $this->supplierRound($supplier);

        $this->guardTarget($submission, $target);

        [$kind, $requirement, $slot] = $this->parseTarget($target);

        try {
            if ($kind === 'type') {
                $rule = $submission->requirements()->where('key', $requirement)->first();

                if ($rule === null) {
                    throw new InvalidArgumentException(__('kyc.batch.refused.unknown_slot'));
                }

                $document = $this->supplierStore->store($submission, $rule, $file);
            } else {
                $this->evidence->file(new SupplierKycRound($submission, $this->supplierStore), $requirement, $slot, $file);

                $document = $submission->documents()->latest('id')->firstOrFail();
            }
        } catch (KycEvidenceRefused $refused) {
            throw new InvalidArgumentException($refused->getMessage());
        }

        $document->forceFill(['uploaded_by_staff_id' => $actor->id])->save();

        $this->record($actor, $submission, $document, $target, $reason, 'supplier_kyc', null);

        if ($verify) {
            $this->verifySupplier($submission, $supplier, $actor, $reason);
        } elseif ($submit) {
            $this->submitSupplier->handle($supplier);
        }

        return $document;
    }

    /**
     * Staff who have the original in front of them confirm it on the spot: the
     * round is sent, every piece of evidence is accepted by the member of staff,
     * and the round is approved — each through the action a reviewer uses, so
     * the review row, the per-document verdicts, the status moves and the audit
     * trail all name who did it. Nothing is faked: a round still missing a
     * required document stays unsent and says what is missing.
     *
     * @throws InvalidArgumentException
     * @throws KycIncomplete
     */
    protected function verifyPartner(KycSubmission $submission, User $actor, string $reason): void
    {
        $this->submitPartner->handle($submission->refresh());

        $submission->refresh();

        try {
            $round = new PartnerKycRound($submission, $this->partnerStore);

            if ($round->definition() !== null) {
                foreach ($round->evidence() as $item) {
                    if (! $item->isAccepted()) {
                        $this->verdicts->handle($round, $actor, $item->requirement, $item->slot, RecordKycVerdict::ACCEPTED);
                    }
                }
            }

            $this->reviewPartner->handle($submission->refresh(), KycDecision::approve($actor->id, 'Verified on the spot by staff: '.$reason));
        } catch (KycReviewRefused $refused) {
            throw new InvalidArgumentException($refused->getMessage());
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    protected function verifySupplier(SupplierKycSubmission $submission, Supplier $supplier, User $actor, string $reason): void
    {
        $this->submitSupplier->handle($supplier);

        $submission->refresh();

        try {
            $round = new SupplierKycRound($submission, $this->supplierStore);

            if ($round->definition() !== null) {
                foreach ($round->evidence() as $item) {
                    if (! $item->isAccepted()) {
                        $this->verdicts->handle($round, $actor, $item->requirement, $item->slot, RecordKycVerdict::ACCEPTED);
                    }
                }
            }

            $this->approveSupplier->handle($submission->refresh(), $actor->id, 'Verified on the spot by staff: '.$reason);
        } catch (KycReviewRefused $refused) {
            throw new InvalidArgumentException($refused->getMessage());
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    protected function partnerRound(BusinessAccount $account): KycSubmission
    {
        $round = $this->openPartnerRound->handle($account);

        $this->guardEditable($round->status->isEditable());

        return $round;
    }

    /**
     * @throws InvalidArgumentException
     */
    protected function supplierRound(Supplier $supplier): SupplierKycSubmission
    {
        $round = $this->openSupplierRound->handle($supplier);

        $this->guardEditable($round->status->isEditable());

        return $round;
    }

    protected function guardEditable(bool $editable): void
    {
        if (! $editable) {
            throw new InvalidArgumentException(
                'This KYC round is awaiting a decision or has been decided, so documents cannot be added to it.',
            );
        }
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    protected function targets(KycSubmission|SupplierKycSubmission $submission): array
    {
        $round = $submission instanceof KycSubmission
            ? new PartnerKycRound($submission, $this->partnerStore)
            : new SupplierKycRound($submission, $this->supplierStore);

        $definition = $round->definition();

        if ($definition === null) {
            return $submission->requirements()
                ->where('requires_file', true)
                ->get()
                ->map(fn ($requirement) => ['value' => 'type:'.$requirement->key, 'label' => $requirement->name])
                ->values()
                ->all();
        }

        $targets = [];

        foreach ($definition->groups() as $group) {
            foreach ($group['requirements'] as $requirement) {
                foreach ($requirement['slots'] as $slot) {
                    if (! in_array($slot['evidence'], ['file', 'image', 'pdf'], true)) {
                        continue;
                    }

                    $targets[] = [
                        'value' => 'slot:'.$requirement['key'].':'.$slot['key'],
                        'label' => $requirement['name'].' — '.$slot['label'],
                    ];
                }
            }
        }

        return $targets;
    }

    /**
     * The target must be one the round actually offers; anything else is a
     * crafted request.
     */
    protected function guardTarget(KycSubmission|SupplierKycSubmission $submission, string $target): void
    {
        $offered = array_column($this->targets($submission), 'value');

        if (! in_array($target, $offered, true)) {
            throw new InvalidArgumentException(__('kyc.batch.refused.unknown_slot'));
        }
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    protected function parseTarget(string $target): array
    {
        $parts = explode(':', $target, 3);

        return [$parts[0], $parts[1] ?? '', $parts[2] ?? ''];
    }

    protected function reason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('A reason is required and is recorded against this upload.');
        }

        return $reason;
    }

    protected function record(
        User $actor,
        Model $submission,
        KycDocument|SupplierKycDocument $document,
        string $target,
        string $reason,
        string $module,
        ?int $accountId,
    ): void {
        $this->audit->handle(new AuditEntry(
            action: $module.'.document_uploaded_on_behalf',
            actorId: $actor->id,
            auditableType: $submission::class,
            auditableId: $submission->getKey(),
            after: [
                'document' => $document->public_id,
                'target' => $target,
                'original_name' => $document->original_name,
                'size_bytes' => $document->size_bytes,
            ],
            reason: $reason,
            accountId: $accountId,
            module: $module,
            isSensitive: true,
        ));
    }
}
