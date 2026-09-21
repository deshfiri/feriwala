<?php

namespace App\Domain\Supplier\Actions;

use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Kyc\Actions\ReviewKyc;
use App\Domain\Supplier\Enums\SupplierKycStatus;
use App\Domain\Supplier\Enums\SupplierStatus;
use App\Domain\Supplier\Enums\SupplierStatusChangeSource;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierKycSubmission;
use App\Notifications\Supplier\SupplierKycCorrectionRequested;
use App\Support\StatusHistory\StatusChange;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Sends a Supplier's KYC round back for correction rather than deciding it
 * (D25, P13-7). Reversible, unlike a rejection: the round stays open for a
 * resubmission through {@see SubmitSupplierKyc} rather than being closed.
 *
 * The user-visible feedback is required — without it the Supplier resubmits
 * the same documents, which wastes their round and the reviewer's queue slot
 * alike (mirrors the generic KYC engine's {@see ReviewKyc}).
 */
class RequestSupplierKycCorrection
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    public function handle(
        SupplierKycSubmission $submission,
        int $reviewerId,
        string $userVisibleFeedback,
        ?string $internalReason = null,
    ): SupplierKycSubmission {
        if (trim($userVisibleFeedback) === '') {
            throw new InvalidArgumentException('Tell the Supplier what to fix — without it they will resubmit the same thing.');
        }

        $supplier = $this->database->transaction(function () use ($submission, $reviewerId, $userVisibleFeedback, $internalReason) {
            /** @var Supplier $locked */
            $locked = Supplier::query()->lockForUpdate()->findOrFail($submission->supplier_id);

            if ($locked->status !== SupplierStatus::UnderReview) {
                throw new InvalidArgumentException('This Supplier is not awaiting a KYC decision.');
            }

            $submission->transitionTo(SupplierKycStatus::CorrectionRequired)->save();
            $submission->forceFill([
                'reviewed_at' => now(),
                'reviewed_by' => $reviewerId,
                'decision_note' => $userVisibleFeedback,
            ])->save();

            $locked->transitionWithHistory(
                SupplierStatus::CorrectionRequired,
                new StatusChange(actorId: $reviewerId, reason: $internalReason ?? $userVisibleFeedback, publicNote: $userVisibleFeedback),
                ['source' => SupplierStatusChangeSource::Staff],
            );

            $this->audit->handle(new AuditEntry(
                action: 'supplier_kyc.correction_requested',
                actorId: $reviewerId,
                auditableType: SupplierKycSubmission::class,
                auditableId: $submission->id,
                before: ['status' => SupplierKycStatus::UnderReview->value],
                after: ['status' => SupplierKycStatus::CorrectionRequired->value],
                reason: $internalReason,
                note: $userVisibleFeedback,
                module: PermissionModule::SupplierKyc->value,
            ));

            return $locked;
        });

        $supplier->notify((new SupplierKycCorrectionRequested($userVisibleFeedback))->locale($supplier->locale));

        return $submission->refresh();
    }
}
