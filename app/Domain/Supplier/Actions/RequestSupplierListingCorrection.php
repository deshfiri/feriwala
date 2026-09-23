<?php

namespace App\Domain\Supplier\Actions;

use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Supplier\Enums\ListingStatus;
use App\Domain\Supplier\Enums\SupplierStatusChangeSource;
use App\Domain\Supplier\Models\SupplierProductListing;
use App\Notifications\Supplier\SupplierListingCorrectionRequested;
use App\Support\StatusHistory\StatusChange;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Sends a Supplier's listing request back for correction (D25, P13-11).
 * Reversible — the Supplier resubmits through {@see SubmitSupplierListing}.
 */
class RequestSupplierListingCorrection
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    public function handle(SupplierProductListing $listing, int $reviewerId, string $userVisibleFeedback, ?string $internalReason = null): SupplierProductListing
    {
        if (trim($userVisibleFeedback) === '') {
            throw new InvalidArgumentException('Tell the Supplier what to fix before requesting a correction.');
        }

        $listing = $this->database->transaction(function () use ($listing, $reviewerId, $userVisibleFeedback, $internalReason) {
            /** @var SupplierProductListing $locked */
            $locked = SupplierProductListing::query()->lockForUpdate()->findOrFail($listing->id);

            if ($locked->status !== ListingStatus::UnderReview) {
                throw new InvalidArgumentException('This listing is not awaiting a decision.');
            }

            $locked->transitionWithHistory(
                ListingStatus::CorrectionRequired,
                new StatusChange(actorId: $reviewerId, reason: $internalReason ?? $userVisibleFeedback, publicNote: $userVisibleFeedback),
                ['source' => SupplierStatusChangeSource::Staff],
            );
            $locked->forceFill(['reviewed_at' => now(), 'reviewed_by' => $reviewerId, 'decision_note' => $userVisibleFeedback])->save();

            $this->audit->handle(new AuditEntry(
                action: 'supplier_listing.correction_requested',
                actorId: $reviewerId,
                auditableType: SupplierProductListing::class,
                auditableId: $locked->id,
                reason: $internalReason,
                note: $userVisibleFeedback,
                module: PermissionModule::SupplierListing->value,
            ));

            return $locked;
        });

        $listing->supplier->notify((new SupplierListingCorrectionRequested($listing, $userVisibleFeedback))->locale($listing->supplier->locale));

        return $listing->refresh();
    }
}
