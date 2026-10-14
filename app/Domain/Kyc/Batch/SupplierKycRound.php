<?php

namespace App\Domain\Kyc\Batch;

use App\Domain\Kyc\Models\KycBatchVersion;
use App\Domain\Supplier\Enums\SupplierKycStatus;
use App\Domain\Supplier\Models\SupplierKycDocument;
use App\Domain\Supplier\Models\SupplierKycEvidenceReview;
use App\Domain\Supplier\Models\SupplierKycSubmission;
use App\Domain\Supplier\SupplierKycDocumentStore;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;

/**
 * A Supplier KYC round, as the batch engine sees it (D30).
 *
 * The Supplier twin of {@see PartnerKycRound}, over the Supplier's own tables
 * (D25). A Supplier document names its requirement by key, so the selfie is
 * simply the reserved key; a correction reopens the same round, which is why a
 * document replaced after submission is superseded and kept — the database
 * refuses to delete one once the round has left draft.
 */
final class SupplierKycRound implements KycRound
{
    public function __construct(
        public readonly SupplierKycSubmission $submission,
        private readonly SupplierKycDocumentStore $store,
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
        return 'supplier';
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

        foreach ($this->submission->documents()->whereNull('superseded_at')->get() as $document) {
            $slot = $document->slot_key ?? 'file';
            $verdict = $this->verdictFor($verdicts, $document->document_type, $slot, $document->public_id, $document->created_at);

            $items[KycEvidenceItem::keyFor($document->document_type, $slot)] = new KycEvidenceItem(
                requirement: $document->document_type,
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

        foreach ($this->submission->fields()->whereNull('superseded_at')->get() as $field) {
            $slot = $field->slot_key ?? 'value';
            $verdict = $this->verdictFor($verdicts, $field->key, $slot, KycEvidenceItem::refForField($field->id), $field->updated_at);

            $items[KycEvidenceItem::keyFor($field->key, $slot)] = new KycEvidenceItem(
                requirement: $field->key,
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
        $this->discard($requirement, $slot);

        $this->store->storeEvidence($this->submission, $requirement, $slot, $file, $capturedLive, $captureMeta);
    }

    public function saveValue(string $requirement, string $slot, string $value): void
    {
        $current = $this->submission->fields()
            ->where('key', $requirement)
            ->where(fn ($query) => $query->where('slot_key', $slot)->orWhereNull('slot_key'))
            ->whereNull('superseded_at')
            ->first();

        if ($current !== null && $this->hasBeenSubmitted()) {
            $current->forceFill(['superseded_at' => now()])->save();
            $current = null;
        }

        if ($current !== null) {
            $current->forceFill(['value' => $value, 'slot_key' => $slot])->save();

            return;
        }

        $this->submission->fields()->create([
            'key' => $requirement,
            'slot_key' => $slot,
            'value' => $value,
        ]);
    }

    public function discard(string $requirement, string $slot): void
    {
        $documents = $this->submission->documents()
            ->whereNull('superseded_at')
            ->where('document_type', $requirement)
            ->where(fn ($query) => $slot === 'file'
                ? $query->where('slot_key', $slot)->orWhereNull('slot_key')
                : $query->where('slot_key', $slot))
            ->get();

        foreach ($documents as $document) {
            $this->retire($document);
        }

        $fields = $this->submission->fields()
            ->whereNull('superseded_at')
            ->where('key', $requirement)
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
        return in_array($this->submission->status, [SupplierKycStatus::Submitted, SupplierKycStatus::UnderReview], true);
    }

    public function revealValue(string $requirement, string $slot): ?string
    {
        $field = $this->submission->fields()
            ->whereNull('superseded_at')
            ->where('key', $requirement)
            ->where(fn ($query) => $query->where('slot_key', $slot)->orWhereNull('slot_key'))
            ->first();

        return $field === null ? null : (string) $field->value;
    }

    public function recordVerdict(string $requirement, string $slot, string $status, ?string $note, int $reviewerId, ?string $evidenceRef = null): void
    {
        SupplierKycEvidenceReview::create([
            'supplier_kyc_submission_id' => $this->submission->id,
            'requirement_key' => $requirement,
            'slot_key' => $slot,
            'status' => $status,
            'note' => $note,
            'reviewed_by' => $reviewerId,
            'evidence_ref' => $evidenceRef,
        ]);
    }

    private function retire(SupplierKycDocument $document): void
    {
        if ($this->hasBeenSubmitted()) {
            $document->forceFill(['superseded_at' => now()])->save();

            return;
        }

        $this->store->deleteDraftFile($document);
    }

    /**
     * @return array<string, SupplierKycEvidenceReview>
     */
    private function latestVerdicts(): array
    {
        $latest = [];

        foreach (SupplierKycEvidenceReview::query()->where('supplier_kyc_submission_id', $this->submission->id)->orderBy('id')->get() as $review) {
            $latest[KycEvidenceItem::keyFor($review->requirement_key, $review->slot_key ?? '')] = $review;
        }

        return $latest;
    }

    /**
     * @param  array<string, SupplierKycEvidenceReview>  $verdicts
     */
    private function verdictFor(array $verdicts, string $requirement, string $slot, string $ref, CarbonInterface $recordedAt): ?SupplierKycEvidenceReview
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
