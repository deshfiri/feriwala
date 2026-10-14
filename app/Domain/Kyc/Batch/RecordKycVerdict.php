<?php

namespace App\Domain\Kyc\Batch;

use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Kyc\Models\KycSubmission;
use App\Domain\Supplier\Models\SupplierKycSubmission;
use App\Models\User;
use Illuminate\Database\DatabaseManager;

/**
 * A reviewer's verdict on one piece of evidence of a round on a batch (D30).
 *
 * Judged per slot, not per round: a NID front can be accepted while its back is
 * sent back. A verdict is append-only — changing one writes a newer row — and a
 * verdict only ever applies to the evidence that was there when it was given, so
 * a replacement waits for its own.
 *
 * The note is the instruction the applicant will read, so a rejection needs one.
 * The audit entry records the shape of the verdict (which slot, accepted or
 * rejected) and never the evidence or the note.
 */
final class RecordKycVerdict
{
    public const ACCEPTED = 'accepted';

    public const REJECTED = 'rejected';

    public function __construct(
        private readonly RecordAuditLog $audit,
        private readonly DatabaseManager $database,
    ) {}

    /**
     * @param  'accepted'|'rejected'  $status
     *
     * @throws KycReviewRefused
     */
    public function handle(KycRound $round, User $by, string $requirement, string $slot, string $status, ?string $note = null): void
    {
        $definition = $round->definition();

        if ($definition === null) {
            throw KycReviewRefused::legacyRound();
        }

        if (! $round->awaitsReview()) {
            throw KycReviewRefused::notAwaitingReview();
        }

        $note = $note === null ? null : trim($note);

        if ($status === self::REJECTED && ($note === null || $note === '')) {
            throw KycReviewRefused::noteRequired();
        }

        // Only a slot the version asks for, and only one that holds something.
        if ($definition->slot($requirement, $slot) === null
            || ! isset($round->evidence()[KycEvidenceItem::keyFor($requirement, $slot)])) {
            throw KycReviewRefused::nothingToJudge();
        }

        $this->database->transaction(function () use ($round, $by, $requirement, $slot, $status, $note) {
            $round->recordVerdict($requirement, $slot, $status, $status === self::REJECTED ? $note : null, $by->id);

            $this->audit($round, $by, $requirement, $slot, $status);
        });
    }

    private function audit(KycRound $round, User $by, string $requirement, string $slot, string $status): void
    {
        if ($round instanceof PartnerKycRound) {
            $this->audit->handle(new AuditEntry(
                action: 'kyc.evidence_reviewed',
                actorId: $by->id,
                auditableType: KycSubmission::class,
                auditableId: $round->submission->id,
                after: ['requirement' => $requirement, 'slot' => $slot, 'status' => $status],
                accountId: $round->submission->business_account_id,
                module: 'kyc',
            ));

            return;
        }

        if ($round instanceof SupplierKycRound) {
            $this->audit->handle(new AuditEntry(
                action: 'supplier_kyc.evidence_reviewed',
                actorId: $by->id,
                auditableType: SupplierKycSubmission::class,
                auditableId: $round->submission->id,
                after: ['requirement' => $requirement, 'slot' => $slot, 'status' => $status],
                module: PermissionModule::SupplierKyc->value,
            ));
        }
    }
}
