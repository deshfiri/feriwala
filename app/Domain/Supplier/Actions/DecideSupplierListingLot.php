<?php

namespace App\Domain\Supplier\Actions;

use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Supplier\Enums\LotStatus;
use App\Domain\Supplier\Enums\SupplierStatusChangeSource;
use App\Domain\Supplier\Models\SupplierProductListingLot;
use App\Models\User;
use App\Support\StatusHistory\StatusChange;
use InvalidArgumentException;
use Throwable;

/**
 * Decides every product entry a reviewer submitted a decision for, inside
 * one lot (Supplier Bulk Product Listing batch), then rolls the lot's own
 * status up from wherever every entry landed.
 *
 * Deliberately {@see BulkSettleSupplierPayables}'s idiom, not
 * {@see SubmitSupplierListingLot}'s: staff review is independent decisions
 * about independent entries, so one entry a reviewer got wrong (a missing
 * reason, an already-decided item) is reported and skipped rather than
 * rolling back every entry decided beside it in the same request. Atomicity
 * belongs to the Supplier's own submission, never to staff review.
 */
class DecideSupplierListingLot
{
    public function __construct(
        protected DecideSupplierListing $decide,
        protected RecordAuditLog $audit,
    ) {}

    /**
     * @param  list<array{listing_id: string, reason: string, product?: array<string, mixed>, items?: list<array<string, mixed>>}>  $decisions
     * @return list<array{listing_id: string, ok: bool, message: ?string}>
     */
    public function handle(SupplierProductListingLot $lot, User $reviewer, array $decisions): array
    {
        if ($lot->status !== LotStatus::UnderReview) {
            throw new InvalidArgumentException('This batch is not awaiting a decision.');
        }

        $entries = $lot->items()->get()->keyBy('public_id');
        $results = [];

        foreach ($decisions as $decision) {
            $entry = $entries->get($decision['listing_id']);

            if ($entry === null) {
                $results[] = ['listing_id' => $decision['listing_id'], 'ok' => false, 'message' => 'This product entry does not belong to this batch.'];

                continue;
            }

            try {
                $this->decide->handle($entry, $reviewer, $decision['product'] ?? [], $decision['items'] ?? [], $decision['reason']);
                $results[] = ['listing_id' => $entry->public_id, 'ok' => true, 'message' => null];
            } catch (Throwable $exception) {
                $results[] = ['listing_id' => $entry->public_id, 'ok' => false, 'message' => $exception->getMessage()];
            }
        }

        $lot = $lot->refresh();
        $newStatus = $lot->rollupStatus();

        // Left at UnderReview when something in the batch is still pending --
        // the same "leave other items pending" rule the listing rollup one
        // level down already follows.
        if ($newStatus !== $lot->status) {
            $lot->transitionWithHistory(
                $newStatus,
                new StatusChange(actorId: $reviewer->id, reason: 'Rolled up from this round\'s product entry decisions.'),
                ['source' => SupplierStatusChangeSource::Staff],
            );
            $lot->forceFill(['reviewed_at' => now(), 'reviewed_by' => $reviewer->id])->save();

            $this->audit->handle(new AuditEntry(
                action: 'supplier_listing_lot.'.$newStatus->value,
                actorId: $reviewer->id,
                auditableType: SupplierProductListingLot::class,
                auditableId: $lot->id,
                after: ['status' => $newStatus->value],
                module: PermissionModule::SupplierListing->value,
            ));
        }

        return $results;
    }
}
