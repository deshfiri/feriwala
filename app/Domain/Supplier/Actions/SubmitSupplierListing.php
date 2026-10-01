<?php

namespace App\Domain\Supplier\Actions;

use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Supplier\Enums\ListingItemStatus;
use App\Domain\Supplier\Enums\ListingStatus;
use App\Domain\Supplier\Enums\SupplierStatusChangeSource;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierProductListing;
use App\Http\Middleware\EnsureSupplierIsOperational;
use App\Notifications\Supplier\SupplierListingSubmitted;
use App\Support\StatusHistory\StatusChange;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Submits (or resubmits) a Supplier's own Product Listing Request (D25,
 * P13-9, P13-11).
 *
 * Only an approved, operational Supplier may submit — checked here as well as
 * by {@see EnsureSupplierIsOperational} on the route, so
 * the guarantee holds even if a future caller reaches this action some other
 * way. Submitting never publishes anything: the listing lands at
 * {@see ListingStatus::UnderReview}, still just a proposal.
 */
class SubmitSupplierListing
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    public function handle(Supplier $supplier, SupplierProductListing $listing, bool $suppressNotification = false): SupplierProductListing
    {
        if (! $supplier->isOperational()) {
            throw new InvalidArgumentException('Only an approved Supplier may submit a listing request.');
        }

        $listing = $this->database->transaction(function () use ($supplier, $listing) {
            /** @var SupplierProductListing $locked */
            $locked = SupplierProductListing::query()->lockForUpdate()->findOrFail($listing->id);

            if ($locked->supplier_id !== $supplier->id) {
                throw new InvalidArgumentException('This listing does not belong to this Supplier.');
            }

            if (! $locked->isEditableBySupplier()) {
                throw new InvalidArgumentException('This listing cannot be submitted from its current status.');
            }

            if ($locked->items()->count() === 0) {
                throw new InvalidArgumentException('At least one product variation is required before submitting.');
            }

            $isResubmission = $locked->status === ListingStatus::CorrectionRequired;

            // A resubmission only reopens the items a reviewer sent back;
            // an item already approved or rejected on an earlier round keeps
            // that outcome rather than being re-queued alongside the fix.
            if ($isResubmission) {
                $locked->items()->where('status', ListingItemStatus::CorrectionRequired->value)
                    ->update(['status' => ListingItemStatus::Pending->value, 'decision_note' => null]);
            }

            $locked->transitionWithHistory(
                ListingStatus::Submitted,
                new StatusChange(reason: $isResubmission ? 'Listing resubmitted.' : 'Listing submitted.'),
                ['source' => SupplierStatusChangeSource::Supplier],
            );
            $locked->forceFill(['submitted_at' => now()])->save();
            $locked->transitionWithHistory(
                ListingStatus::UnderReview,
                new StatusChange(reason: 'Listing awaiting staff review.'),
                ['source' => SupplierStatusChangeSource::Supplier],
            );

            $this->audit->handle(new AuditEntry(
                action: $isResubmission ? 'supplier_listing.resubmitted' : 'supplier_listing.submitted',
                actorType: 'supplier',
                actorLabel: $supplier->business_name,
                auditableType: SupplierProductListing::class,
                auditableId: $locked->id,
                after: ['status' => ListingStatus::UnderReview->value],
                module: PermissionModule::SupplierListing->value,
            ));

            return $locked;
        });

        // A lot-level submission fires exactly one summary notification
        // itself (SubmitSupplierListingLot) rather than one per product
        // entry -- suppressed here, never skipped for a single-listing
        // submission outside a lot.
        if (! $suppressNotification) {
            $supplier->notify((new SupplierListingSubmitted($listing))->locale($supplier->locale));
        }

        return $listing->refresh();
    }
}
