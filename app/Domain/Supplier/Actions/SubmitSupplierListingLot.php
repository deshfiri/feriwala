<?php

namespace App\Domain\Supplier\Actions;

use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Supplier\Enums\ListingStatus;
use App\Domain\Supplier\Enums\LotStatus;
use App\Domain\Supplier\Enums\SupplierStatusChangeSource;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierProductListingLot;
use App\Notifications\Supplier\SupplierListingLotSubmitted;
use App\Support\StatusHistory\StatusChange;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Submits every submittable product entry in a Supplier's lot, once, all or
 * nothing (Supplier Bulk Product Listing batch).
 *
 * Deliberately **not** {@see BulkSettleSupplierPayables}'s
 * per-item-own-transaction idiom: that idiom is for staff *review*
 * (independent decisions, a bad row must not block the good ones); a
 * Supplier's own submission is one decision about the whole batch, so this
 * validates every entry first and, if any is invalid, writes nothing at
 * all -- see {@see ValidateSupplierListingLotForSubmission}.
 *
 * Idempotent: resubmitting a lot with nothing left in `Draft`/
 * `CorrectionRequired` is a clean, item-keyed refusal rather than a
 * half-completed submission or a 500.
 */
class SubmitSupplierListingLot
{
    public function __construct(
        protected ValidateSupplierListingLotForSubmission $validator,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    public function handle(Supplier $supplier, SupplierProductListingLot $lot): SupplierProductListingLot
    {
        if (! $supplier->isOperational()) {
            throw new InvalidArgumentException('Only an approved Supplier may submit a listing lot.');
        }

        $lot = $this->database->transaction(function () use ($supplier, $lot) {
            /** @var SupplierProductListingLot $locked */
            $locked = SupplierProductListingLot::query()->lockForUpdate()->findOrFail($lot->id);

            if ($locked->supplier_id !== $supplier->id) {
                throw new InvalidArgumentException('This lot does not belong to this Supplier.');
            }

            // Lock every candidate row before re-validating, so a
            // concurrent edit cannot slip in between the check and the
            // write this same transaction is about to make.
            $locked->items()->lockForUpdate()->get();

            $errors = $this->validator->handle($locked);

            if ($errors !== []) {
                throw ValidationException::withMessages(
                    collect($errors)->mapWithKeys(fn (array $messages, string $key) => ["items.{$key}" => $messages])->all(),
                );
            }

            $submittable = $this->validator->submittableItems($locked);
            $wasResubmission = $submittable->contains(fn ($entry) => $entry->status === ListingStatus::CorrectionRequired);

            foreach ($submittable as $entry) {
                app(SubmitSupplierListing::class)->handle($supplier, $entry, suppressNotification: true);
            }

            // The lot's own state machine only reaches UnderReview through
            // Submitted, from either Draft or Returned -- the same two-step
            // shape SubmitSupplierListing already uses one level down.
            // Every entry just submitted lands at UnderReview, so that is
            // always where the lot ends up too, whichever state it started
            // from and however many entries it held before this round.
            $locked->transitionWithHistory(
                LotStatus::Submitted,
                new StatusChange(reason: $wasResubmission ? 'Lot resubmitted.' : 'Lot submitted.'),
                ['source' => SupplierStatusChangeSource::Supplier],
            );
            $locked->forceFill(['submitted_at' => $locked->submitted_at ?? now()])->save();
            $locked->transitionWithHistory(
                LotStatus::UnderReview,
                new StatusChange(reason: 'Lot awaiting staff review.'),
                ['source' => SupplierStatusChangeSource::Supplier],
            );

            $this->audit->handle(new AuditEntry(
                action: $wasResubmission ? 'supplier_listing_lot.resubmitted' : 'supplier_listing_lot.submitted',
                actorType: 'supplier',
                actorLabel: $supplier->business_name,
                auditableType: SupplierProductListingLot::class,
                auditableId: $locked->id,
                after: ['status' => LotStatus::UnderReview->value, 'item_count' => $submittable->count()],
                module: PermissionModule::SupplierListing->value,
            ));

            return $locked;
        });

        $lot = $lot->refresh();

        $supplier->notify((new SupplierListingLotSubmitted($lot))->locale($supplier->locale));

        return $lot;
    }
}
