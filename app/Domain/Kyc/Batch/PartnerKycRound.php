<?php

namespace App\Domain\Kyc\Batch;

use App\Domain\Kyc\KycDocumentStore;
use App\Domain\Kyc\Models\KycBatchVersion;
use App\Domain\Kyc\Models\KycDocument;
use App\Domain\Kyc\Models\KycDocumentType;
use App\Domain\Kyc\Models\KycEvidenceReview;
use App\Domain\Kyc\Models\KycSubmission;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;

/**
 * A Client/Partner KYC round, as the batch engine sees it (D30).
 *
 * Maps the engine's (requirement, slot) addressing onto the Partner tables: a
 * requirement is a `kyc_document_types` row found by its key, a slot is the
 * `slot_key` on a document or typed value, and the selfie is a document with no
 * catalogue type. A file or value written before slots existed has no slot key
 * and is read as the requirement's `file` / `value` slot.
 */
final class PartnerKycRound implements KycRound
{
    public function __construct(
        public readonly KycSubmission $submission,
        private readonly KycDocumentStore $store,
    ) {}

    public function definition(): ?KycRoundDefinition
    {
        $versionId = $this->submission->kyc_batch_version_id;

        if ($versionId === null) {
            return null;
        }

        return KycRoundDefinition::fromVersion(KycBatchVersion::query()->whereKey($versionId)->firstOrFail());
    }

    public function audience(): string
    {
        return 'account';
    }

    public function isEditable(): bool
    {
        return $this->submission->status->isEditable();
    }

    public function hasBeenSubmitted(): bool
    {
        return $this->submission->submitted_at !== null;
    }

    public function selections(): array
    {
        return $this->submission->selections ?? [];
    }

    public function saveSelections(array $selections): void
    {
        $this->submission->forceFill(['selections' => $selections === [] ? null : $selections])->save();
    }

    public function evidence(): array
    {
        $verdicts = $this->latestVerdicts();
        $items = [];

        $documents = $this->submission->documents()->whereNull('superseded_at')->with('documentType:id,key')->get();

        foreach ($documents as $document) {
            // No catalogue type means the live selfie.
            $requirement = $document->kyc_document_type_id === null
                ? KycRoundDefinition::SELFIE_REQUIREMENT
                : (string) $document->documentType?->key;
            $slot = $document->slot_key ?? 'file';
            $verdict = $this->verdictFor($verdicts, $requirement, $slot, $document->public_id, $document->created_at);

            $items[KycEvidenceItem::keyFor($requirement, $slot)] = new KycEvidenceItem(
                requirement: $requirement,
                slot: $slot,
                kind: KycEvidenceItem::FILE,
                recordedAt: $document->created_at,
                documentId: $document->public_id,
                ref: $document->public_id,
                name: $document->original_name,
                sizeBytes: $document->size_bytes,
                mime: $document->mime_type,
                capturedLive: $document->captured_live,
                verdict: $verdict?->status,
                note: $verdict?->note,
            );
        }

        $fields = $this->submission->fields()->whereNull('superseded_at')->with('documentType:id,key')->get();

        foreach ($fields as $field) {
            $requirement = $field->documentType?->key;

            if ($requirement === null) {
                continue;
            }

            $slot = $field->slot_key ?? 'value';
            $verdict = $this->verdictFor($verdicts, $requirement, $slot, KycEvidenceItem::refForField($field->id), $field->updated_at);

            $items[KycEvidenceItem::keyFor($requirement, $slot)] = new KycEvidenceItem(
                requirement: $requirement,
                slot: $slot,
                kind: KycEvidenceItem::VALUE,
                recordedAt: $field->updated_at,
                maskedValue: $field->masked(),
                ref: KycEvidenceItem::refForField($field->id),
                verdict: $verdict?->status,
                note: $verdict?->note,
            );
        }

        return $items;
    }

    public function attachFile(string $requirement, string $slot, UploadedFile $file, bool $capturedLive = false, ?array $captureMeta = null): void
    {
        $type = $this->type($requirement);

        $this->discard($requirement, $slot);

        $this->store->storeEvidence($this->submission, $type, $slot, $file, $capturedLive, $captureMeta);
    }

    public function saveValue(string $requirement, string $slot, string $value): void
    {
        $type = $this->type($requirement);

        $current = $this->submission->fields()
            ->where('kyc_document_type_id', $type?->id)
            ->where(fn ($query) => $query->where('slot_key', $slot)->orWhereNull('slot_key'))
            ->whereNull('superseded_at')
            ->first();

        if ($current !== null && $this->hasBeenSubmitted()) {
            // What was first written stays on record.
            $current->forceFill(['superseded_at' => now()])->save();
            $current = null;
        }

        if ($current !== null) {
            $current->forceFill(['value' => $value, 'slot_key' => $slot])->save();

            return;
        }

        $this->submission->fields()->create([
            'kyc_document_type_id' => $type?->id,
            'slot_key' => $slot,
            'value' => $value,
        ]);
    }

    public function discard(string $requirement, string $slot): void
    {
        $type = $this->type($requirement);

        $documents = $this->submission->documents()
            ->whereNull('superseded_at')
            ->where('kyc_document_type_id', $type?->id)
            ->where(fn ($query) => $slot === 'file'
                ? $query->where('slot_key', $slot)->orWhereNull('slot_key')
                : $query->where('slot_key', $slot))
            ->get();

        foreach ($documents as $document) {
            $this->retire($document);
        }

        $fields = $this->submission->fields()
            ->whereNull('superseded_at')
            ->where('kyc_document_type_id', $type?->id)
            ->where(fn ($query) => $slot === 'value'
                ? $query->where('slot_key', $slot)->orWhereNull('slot_key')
                : $query->where('slot_key', $slot))
            ->get();

        foreach ($fields as $field) {
            $this->hasBeenSubmitted()
                ? $field->forceFill(['superseded_at' => now()])->save()
                : $field->delete();
        }
    }

    public function awaitsReview(): bool
    {
        return $this->submission->status->awaitsReview();
    }

    public function revealValue(string $requirement, string $slot): ?string
    {
        $type = $this->type($requirement);

        $field = $this->submission->fields()
            ->whereNull('superseded_at')
            ->where('kyc_document_type_id', $type?->id)
            ->where(fn ($query) => $query->where('slot_key', $slot)->orWhereNull('slot_key'))
            ->first();

        return $field === null ? null : (string) $field->value;
    }

    public function recordVerdict(string $requirement, string $slot, string $status, ?string $note, int $reviewerId, ?string $evidenceRef = null): void
    {
        KycEvidenceReview::create([
            'kyc_submission_id' => $this->submission->id,
            'requirement_key' => $requirement,
            'slot_key' => $slot,
            'status' => $status,
            'note' => $note,
            'reviewed_by' => $reviewerId,
            'evidence_ref' => $evidenceRef,
        ]);
    }

    private function retire(KycDocument $document): void
    {
        if ($this->hasBeenSubmitted()) {
            $document->forceFill(['superseded_at' => now()])->save();

            return;
        }

        $this->store->deleteDraftFile($document);
    }

    private function type(string $requirement): ?KycDocumentType
    {
        if ($requirement === KycRoundDefinition::SELFIE_REQUIREMENT) {
            return null;
        }

        return KycDocumentType::query()->where('key', $requirement)->firstOrFail();
    }

    /**
     * The newest verdict on every slot: "requirement|slot" to the review.
     *
     * @return array<string, KycEvidenceReview>
     */
    private function latestVerdicts(): array
    {
        $latest = [];

        foreach (KycEvidenceReview::query()->where('kyc_submission_id', $this->submission->id)->orderBy('id')->get() as $review) {
            $latest[KycEvidenceItem::keyFor($review->requirement_key, $review->slot_key ?? '')] = $review;
        }

        return $latest;
    }

    /**
     * A verdict only judges the evidence that existed when it was given: a file
     * replaced afterwards is new, and waits for its own.
     *
     * @param  array<string, KycEvidenceReview>  $verdicts
     */
    private function verdictFor(array $verdicts, string $requirement, string $slot, string $ref, CarbonInterface $recordedAt): ?KycEvidenceReview
    {
        $review = $verdicts[KycEvidenceItem::keyFor($requirement, $slot)] ?? null;

        if ($review === null) {
            return null;
        }

        // A verdict names the evidence it judged, so a replacement — even one made in
        // the same second — is never read as already judged.
        if ($review->evidence_ref !== null) {
            return $review->evidence_ref === $ref ? $review : null;
        }

        // Verdicts written before they named their evidence: matched by time.
        return $review->created_at->greaterThanOrEqualTo($recordedAt) ? $review : null;
    }
}
