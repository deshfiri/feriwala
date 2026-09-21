<?php

namespace App\Domain\Supplier\Actions;

use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Account\Actions\SuspendAccount;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Supplier\Enums\SupplierKycStatus;
use App\Domain\Supplier\Enums\SupplierStatus;
use App\Domain\Supplier\Enums\SupplierStatusChangeSource;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierKycSubmission;
use App\Notifications\Supplier\SupplierRejected;
use App\Support\StatusHistory\StatusChange;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Rejects a Supplier's KYC round (D25, P13-7).
 *
 * An internal reason is required and is not optional wording — it is the
 * record of why the platform declined this business, which has to survive
 * the reviewer who decided it (mirrors {@see SuspendAccount}).
 * The applicant-facing note is separate, so a private assessment never
 * reaches what the Supplier reads.
 */
class RejectSupplierKyc
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    public function handle(
        SupplierKycSubmission $submission,
        int $reviewerId,
        string $reason,
        ?string $userVisibleNote = null,
    ): SupplierKycSubmission {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Rejecting a Supplier requires a recorded reason.');
        }

        $supplier = $this->database->transaction(function () use ($submission, $reviewerId, $reason, $userVisibleNote) {
            /** @var Supplier $locked */
            $locked = Supplier::query()->lockForUpdate()->findOrFail($submission->supplier_id);

            if ($locked->status !== SupplierStatus::UnderReview) {
                throw new InvalidArgumentException('This Supplier is not awaiting a KYC decision.');
            }

            $submission->transitionTo(SupplierKycStatus::Rejected)->save();
            $submission->forceFill([
                'reviewed_at' => now(),
                'reviewed_by' => $reviewerId,
                'decision_note' => $userVisibleNote,
            ])->save();

            $locked->transitionWithHistory(
                SupplierStatus::Rejected,
                new StatusChange(actorId: $reviewerId, reason: $reason, publicNote: $userVisibleNote),
                ['source' => SupplierStatusChangeSource::Staff],
            );

            $this->audit->handle(new AuditEntry(
                action: 'supplier.rejected',
                actorId: $reviewerId,
                auditableType: Supplier::class,
                auditableId: $locked->id,
                before: ['status' => SupplierStatus::UnderReview->value],
                after: ['status' => SupplierStatus::Rejected->value],
                reason: $reason,
                note: $userVisibleNote,
                module: PermissionModule::Supplier->value,
                isSensitive: true,
            ));

            return $locked;
        });

        $supplier->notify((new SupplierRejected($userVisibleNote))->locale($supplier->locale));

        return $submission->refresh();
    }
}
