<?php

namespace App\Domain\Supplier\Actions;

use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Supplier\Enums\SupplierKycStatus;
use App\Domain\Supplier\Enums\SupplierStatus;
use App\Domain\Supplier\Enums\SupplierStatusChangeSource;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierKycSubmission;
use App\Notifications\Supplier\SupplierApproved;
use App\Support\StatusHistory\StatusChange;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Approves a Supplier's KYC round (D25, P13-7).
 *
 * This is the one action that activates operational Supplier access:
 * approving the round moves the Supplier itself to
 * {@see SupplierStatus::Approved}, and {@see Supplier::isOperational()} —
 * which every server-side Supplier guard asks — answers true from this
 * moment on.
 */
class ApproveSupplierKyc
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    public function handle(SupplierKycSubmission $submission, int $reviewerId, ?string $decisionNote = null): SupplierKycSubmission
    {
        $supplier = $this->database->transaction(function () use ($submission, $reviewerId, $decisionNote) {
            /** @var Supplier $locked */
            $locked = Supplier::query()->lockForUpdate()->findOrFail($submission->supplier_id);

            if ($locked->status !== SupplierStatus::UnderReview) {
                throw new InvalidArgumentException('This Supplier is not awaiting a KYC decision.');
            }

            $submission->transitionTo(SupplierKycStatus::Approved)->save();
            $submission->forceFill([
                'reviewed_at' => now(),
                'reviewed_by' => $reviewerId,
                'decision_note' => $decisionNote,
            ])->save();

            $locked->transitionWithHistory(
                SupplierStatus::Approved,
                new StatusChange(actorId: $reviewerId, reason: $decisionNote ?? 'KYC approved.'),
                ['source' => SupplierStatusChangeSource::Staff],
            );

            $this->audit->handle(new AuditEntry(
                action: 'supplier.approved',
                actorId: $reviewerId,
                auditableType: Supplier::class,
                auditableId: $locked->id,
                before: ['status' => SupplierStatus::UnderReview->value],
                after: ['status' => SupplierStatus::Approved->value],
                reason: $decisionNote,
                module: PermissionModule::Supplier->value,
            ));

            return $locked;
        });

        $supplier->notify((new SupplierApproved)->locale($supplier->locale));

        return $submission->refresh();
    }
}
