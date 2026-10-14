<?php

namespace App\Domain\Kyc\Batch;

use App\Domain\Kyc\KycDocumentStore;
use App\Domain\Kyc\Models\KycEvidenceReview;
use App\Domain\Kyc\Models\KycSubmission;

/**
 * Brings what a reviewer already accepted into the next Partner round on the same
 * batch version, so a correction asks only for what failed (D30).
 *
 * A Partner correction is a new round, never an edit of the one that was
 * reviewed. Without this, the applicant would re-upload a NID front that was
 * never in question. Each accepted slot is copied as a fresh row pointing at the
 * same stored file (nothing is re-encrypted or duplicated on disk), together with
 * its accepted verdict and the option choices — so the new round starts with those
 * slots locked and satisfied, and the rejected or missing ones open.
 *
 * Rejected and unreviewed evidence is left behind: it stays on the earlier round
 * as the record of what was judged. The earlier round is not touched.
 */
final class CarryForwardKycEvidence
{
    public function __construct(
        private readonly KycDocumentStore $store,
    ) {}

    public function handle(KycSubmission $from, KycSubmission $to): int
    {
        if ($from->kyc_batch_version_id === null || $from->kyc_batch_version_id !== $to->kyc_batch_version_id) {
            return 0;
        }

        $accepted = array_filter(
            (new PartnerKycRound($from, $this->store))->evidence(),
            fn (KycEvidenceItem $item) => $item->isAccepted(),
        );

        if ($accepted === []) {
            return 0;
        }

        $carried = 0;

        foreach ($accepted as $item) {
            $item->kind === KycEvidenceItem::FILE
                ? $this->copyDocument($from, $to, $item)
                : $this->copyField($from, $to, $item);

            $verdict = KycEvidenceReview::query()
                ->where('kyc_submission_id', $from->id)
                ->where('requirement_key', $item->requirement)
                ->where('slot_key', $item->slot)
                ->orderByDesc('id')
                ->first();

            // Written after the copy, so it is at least as new as the evidence it
            // judges and the round reads it as applying.
            KycEvidenceReview::create([
                'kyc_submission_id' => $to->id,
                'requirement_key' => $item->requirement,
                'slot_key' => $item->slot,
                'status' => KycEvidenceReview::ACCEPTED,
                'note' => null,
                'reviewed_by' => $verdict?->reviewed_by,
            ]);

            $carried++;
        }

        // The options the applicant chose stay chosen, or the group could not
        // count what was carried.
        if ($from->selections !== null) {
            $to->forceFill(['selections' => $from->selections])->save();
        }

        return $carried;
    }

    private function copyDocument(KycSubmission $from, KycSubmission $to, KycEvidenceItem $item): void
    {
        $document = $from->documents()
            ->whereNull('superseded_at')
            ->where('public_id', $item->documentId)
            ->first();

        if ($document === null) {
            return;
        }

        $copy = $document->replicate();
        $copy->forceFill(['kyc_submission_id' => $to->id, 'public_id' => null])->save();
    }

    private function copyField(KycSubmission $from, KycSubmission $to, KycEvidenceItem $item): void
    {
        $field = $from->fields()
            ->whereNull('superseded_at')
            ->whereHas('documentType', fn ($query) => $query->where('key', $item->requirement))
            ->where(fn ($query) => $query->where('slot_key', $item->slot)->orWhereNull('slot_key'))
            ->first();

        if ($field === null) {
            return;
        }

        $field->replicate()->forceFill(['kyc_submission_id' => $to->id, 'slot_key' => $item->slot])->save();
    }
}
