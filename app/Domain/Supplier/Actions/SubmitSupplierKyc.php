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
use App\Domain\Supplier\Models\SupplierStatusChange;
use App\Notifications\Supplier\SupplierKycSubmitted;
use App\Support\StatusHistory\StatusChange;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Submits (or resubmits) the Supplier's current KYC round (D25, P13-7).
 *
 * Both directions land the round at {@see SupplierKycStatus::UnderReview} in
 * one step — there is no separate "start review" click in this beta, so a
 * Supplier's submission appears directly in the staff queue. The Supplier's
 * own overall status moves alongside it, recorded in `supplier_status_history`
 * via {@see SupplierStatusChange} — the KYC round
 * itself carries no separate history table (D25's schema note).
 */
class SubmitSupplierKyc
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    public function handle(Supplier $supplier): SupplierKycSubmission
    {
        $submission = $this->database->transaction(function () use ($supplier) {
            /** @var Supplier $locked */
            $locked = Supplier::query()->lockForUpdate()->findOrFail($supplier->id);

            $submission = $locked->kycSubmissions()->first();

            if ($submission === null || ! $submission->status->isEditable()) {
                throw new InvalidArgumentException('There is no editable Supplier KYC round to submit.');
            }

            if ($submission->documents()->count() === 0) {
                throw new InvalidArgumentException('At least one document is required before submitting.');
            }

            $isResubmission = $submission->status === SupplierKycStatus::CorrectionRequired;

            $submission->transitionTo(SupplierKycStatus::Submitted)->save();
            $submission->forceFill(['submitted_at' => now()])->save();
            $submission->transitionTo(SupplierKycStatus::UnderReview)->save();

            $expectedFrom = $isResubmission ? SupplierStatus::CorrectionRequired : SupplierStatus::KycPending;

            if ($locked->status !== $expectedFrom) {
                throw new InvalidArgumentException('The Supplier is not in a state that allows KYC submission.');
            }

            $locked->transitionWithHistory(
                SupplierStatus::UnderReview,
                new StatusChange(reason: $isResubmission ? 'KYC resubmitted for review.' : 'KYC submitted for review.'),
                ['source' => SupplierStatusChangeSource::Supplier],
            );

            $this->audit->handle(new AuditEntry(
                action: $isResubmission ? 'supplier_kyc.resubmitted' : 'supplier_kyc.submitted',
                actorId: null,
                actorType: 'supplier',
                actorLabel: $locked->business_name,
                auditableType: SupplierKycSubmission::class,
                auditableId: $submission->id,
                after: ['status' => SupplierKycStatus::UnderReview->value, 'round' => $submission->round],
                module: PermissionModule::SupplierKyc->value,
            ));

            $supplier->setRawAttributes($locked->getAttributes(), sync: true);

            return $submission->refresh();
        });

        $supplier->notify((new SupplierKycSubmitted)->locale($supplier->locale));

        return $submission;
    }
}
