<?php

namespace App\Domain\Kyc\Actions;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Kyc\Batch\KycEvidenceItem;
use App\Domain\Kyc\Batch\KycEvidenceRefused;
use App\Domain\Kyc\Batch\KycReviewRefused;
use App\Domain\Kyc\Batch\KycRoundDefinition;
use App\Domain\Kyc\Batch\PartnerKycRound;
use App\Domain\Kyc\Batch\RecordKycEvidence;
use App\Domain\Kyc\Batch\RecordKycVerdict;
use App\Domain\Kyc\Batch\SupplierKycRound;
use App\Domain\Kyc\Data\KycDecision;
use App\Domain\Kyc\Enums\KycCompletionRule;
use App\Domain\Kyc\Exceptions\KycIncomplete;
use App\Domain\Kyc\Exceptions\KycUploadRefused;
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
     * @return array<int, array{value: string, kind: string, option: string, label: string, group: string|null, group_rule: string|null, required: bool, uploaded: bool}>
     *
     * @throws InvalidArgumentException when the round cannot take uploads
     */
    public function partnerTargets(BusinessAccount $account): array
    {
        return $this->targets($this->partnerRound($account));
    }

    /**
     * @return array<int, array{value: string, kind: string, option: string, label: string, group: string|null, group_rule: string|null, required: bool, uploaded: bool}>
     *
     * @throws InvalidArgumentException
     */
    public function supplierTargets(Supplier $supplier): array
    {
        return $this->targets($this->supplierRound($supplier));
    }

    /**
     * One file, one target. The same as {@see forPartnerMany()} with a single entry.
     *
     * @throws InvalidArgumentException
     * @throws KycIncomplete
     */
    public function forPartner(BusinessAccount $account, User $actor, string $target, UploadedFile $file, string $reason, bool $submit = false, bool $verify = false): KycDocument
    {
        return $this->forPartnerMany($account, $actor, [$target => $file], $reason, $submit, $verify)[0];
    }

    /**
     * Several documents of one round at once — everything a batch asks for in
     * one sitting.
     *
     * Every target is checked against what the round offers before the first
     * file is stored. They are then stored in order; a file the round refuses
     * stops the rest and names its target ({@see KycUploadRefused}), and what
     * was stored before it stays, because each is a real draft document. The
     * round is sent and verified only once all of them are in.
     *
     * Typed values and declarations of a batch (a TIN, a tick) go the same way,
     * in `$values`, and are recorded through the same evidence rules.
     *
     * @param  array<string, UploadedFile>  $files  target => file
     * @param  array<string, string>  $values  target => typed value ("1" for a declaration)
     * @return array<int, KycDocument>
     *
     * @throws InvalidArgumentException
     * @throws KycIncomplete
     */
    public function forPartnerMany(BusinessAccount $account, User $actor, array $files, string $reason, bool $submit = false, bool $verify = false, array $values = []): array
    {
        $reason = $this->reason($reason);
        $submission = $this->partnerRound($account);

        foreach ([...array_keys($files), ...array_keys($values)] as $target) {
            $this->guardTarget($submission, (string) $target);
        }

        $documents = [];

        foreach ($files as $target => $file) {
            $documents[] = $this->storeForPartner($submission, $actor, (string) $target, $file, $reason, $account->id);
        }

        foreach ($values as $target => $value) {
            $this->storeValueForPartner($submission, $actor, (string) $target, $value, $reason, $account->id);
        }

        if ($verify) {
            $this->verifyPartner($submission, $actor, $reason);
        } elseif ($submit) {
            $this->submitPartner->handle($submission->refresh());
        }

        return $documents;
    }

    /**
     * @throws InvalidArgumentException
     */
    public function forSupplier(Supplier $supplier, User $actor, string $target, UploadedFile $file, string $reason, bool $submit = false, bool $verify = false): SupplierKycDocument
    {
        return $this->forSupplierMany($supplier, $actor, [$target => $file], $reason, $submit, $verify)[0];
    }

    /**
     * {@see forPartnerMany()} for a Supplier's round.
     *
     * @param  array<string, UploadedFile>  $files  target => file
     * @param  array<string, string>  $values  target => typed value ("1" for a declaration)
     * @return array<int, SupplierKycDocument>
     *
     * @throws InvalidArgumentException
     */
    public function forSupplierMany(Supplier $supplier, User $actor, array $files, string $reason, bool $submit = false, bool $verify = false, array $values = []): array
    {
        $reason = $this->reason($reason);
        $submission = $this->supplierRound($supplier);

        foreach ([...array_keys($files), ...array_keys($values)] as $target) {
            $this->guardTarget($submission, (string) $target);
        }

        $documents = [];

        foreach ($files as $target => $file) {
            $documents[] = $this->storeForSupplier($submission, $actor, (string) $target, $file, $reason);
        }

        foreach ($values as $target => $value) {
            $this->storeValueForSupplier($submission, $actor, (string) $target, $value, $reason);
        }

        if ($verify) {
            $this->verifySupplier($submission, $supplier, $actor, $reason);
        } elseif ($submit) {
            $this->submitSupplier->handle($supplier);
        }

        return $documents;
    }

    /**
     * A typed value or declaration of a batch round, recorded as the applicant's
     * own would be. The audit entry names the slot and who and why, never the
     * value: a TIN or a NID number does not belong in the audit log.
     *
     * @throws KycUploadRefused
     */
    protected function storeValueForPartner(KycSubmission $submission, User $actor, string $target, string $value, string $reason, int $accountId): void
    {
        [, $requirement, $slot] = $this->parseTarget($target);

        try {
            $this->evidence->value(new PartnerKycRound($submission, $this->partnerStore), $requirement, $slot, $value);
        } catch (KycEvidenceRefused $refused) {
            throw new KycUploadRefused($target, $refused->getMessage());
        }

        $this->recordValue($actor, $submission, $target, $reason, 'kyc', $accountId);
    }

    /**
     * @throws KycUploadRefused
     */
    protected function storeValueForSupplier(SupplierKycSubmission $submission, User $actor, string $target, string $value, string $reason): void
    {
        [, $requirement, $slot] = $this->parseTarget($target);

        try {
            $this->evidence->value(new SupplierKycRound($submission, $this->supplierStore), $requirement, $slot, $value);
        } catch (KycEvidenceRefused $refused) {
            throw new KycUploadRefused($target, $refused->getMessage());
        }

        $this->recordValue($actor, $submission, $target, $reason, 'supplier_kyc', null);
    }

    /**
     * @throws KycUploadRefused
     */
    protected function storeForPartner(KycSubmission $submission, User $actor, string $target, UploadedFile $file, string $reason, int $accountId): KycDocument
    {
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
        } catch (KycEvidenceRefused|InvalidArgumentException $refused) {
            throw new KycUploadRefused($target, $refused->getMessage());
        }

        $document->forceFill(['uploaded_by_staff_id' => $actor->id])->save();

        $this->record($actor, $submission, $document, $target, $reason, 'kyc', $accountId);

        return $document;
    }

    /**
     * @throws KycUploadRefused
     */
    protected function storeForSupplier(SupplierKycSubmission $submission, User $actor, string $target, UploadedFile $file, string $reason): SupplierKycDocument
    {
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
        } catch (KycEvidenceRefused|InvalidArgumentException $refused) {
            throw new KycUploadRefused($target, $refused->getMessage());
        }

        $document->forceFill(['uploaded_by_staff_id' => $actor->id])->save();

        $this->record($actor, $submission, $document, $target, $reason, 'supplier_kyc', null);

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
     * Everything the round takes a file for, with what the screen needs to lay
     * the whole checklist out: the group it belongs to, whether it is owed, and
     * whether something is already there.
     *
     * @return array<int, array{value: string, kind: string, option: string, label: string, group: string|null, group_rule: string|null, required: bool, uploaded: bool}>
     */
    protected function targets(KycSubmission|SupplierKycSubmission $submission): array
    {
        $round = $submission instanceof KycSubmission
            ? new PartnerKycRound($submission, $this->partnerStore)
            : new SupplierKycRound($submission, $this->supplierStore);

        $definition = $round->definition();

        if ($definition === null) {
            $present = $submission instanceof KycSubmission
                ? $submission->documents()->with('documentType:id,key')->get()->map(fn ($document) => $document->documentType?->key)->filter()->all()
                : $submission->documents()->pluck('document_type')->all();

            return $submission->requirements()
                ->where('requires_file', true)
                ->get()
                ->map(fn ($requirement) => [
                    'value' => 'type:'.$requirement->key,
                    'kind' => 'file',
                    'option' => $requirement->name,
                    'label' => $requirement->name,
                    'group' => null,
                    'group_rule' => null,
                    'required' => (bool) $requirement->is_required,
                    'uploaded' => in_array($requirement->key, $present, true),
                ])
                ->values()
                ->all();
        }

        $evidence = $round->evidence();
        $targets = [];

        foreach ($definition->groups() as $group) {
            foreach ($group['requirements'] as $requirement) {
                foreach ($requirement['slots'] as $slot) {
                    if (! in_array($slot['evidence'], ['file', 'image', 'pdf', 'value', 'declaration'], true)) {
                        continue;
                    }

                    $targets[] = [
                        'value' => 'slot:'.$requirement['key'].':'.$slot['key'],
                        'kind' => in_array($slot['evidence'], ['value', 'declaration'], true) ? $slot['evidence'] : 'file',
                        'option' => $requirement['name'],
                        'label' => $requirement['name'].' — '.$slot['label'],
                        'group' => KycRoundDefinition::localized($group, 'name'),
                        'group_rule' => $group['rule'],

                        // Owed outright only in a group that asks for all of it;
                        // in an "any one" group the applicant chooses.
                        'required' => $group['rule'] === KycCompletionRule::All->value
                            && $requirement['required']
                            && $slot['required'],
                        'uploaded' => isset($evidence[KycEvidenceItem::keyFor($requirement['key'], $slot['key'])]),
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

    protected function recordValue(User $actor, Model $submission, string $target, string $reason, string $module, ?int $accountId): void
    {
        $this->audit->handle(new AuditEntry(
            action: $module.'.value_recorded_on_behalf',
            actorId: $actor->id,
            auditableType: $submission::class,
            auditableId: $submission->getKey(),
            after: ['target' => $target],
            reason: $reason,
            accountId: $accountId,
            module: $module,
            isSensitive: true,
        ));
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
