<?php

namespace App\Domain\Supplier\Actions;

use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Order\Models\OrderItemAllocation;
use App\Domain\Supplier\Enums\FulfilmentCommitmentStatus;
use App\Domain\Supplier\Enums\SupplierStatusChangeSource;
use App\Domain\Supplier\Exceptions\FulfilmentCapacityExhausted;
use App\Domain\Supplier\Models\SupplierFulfilmentCommitment;
use App\Domain\Supplier\Models\SupplierOffer;
use App\Support\StatusHistory\StatusChange;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use LogicException;

/**
 * Commits a Supplier to fulfil one order line from an on_demand or
 * pre_order offer -- the concurrency-safe capacity reservation the batch's
 * own spec requires (correction 7).
 *
 * `forAllocation()` **is** the reservation: it locks the {@see SupplierOffer}
 * row, sums every non-terminal commitment already against it, and refuses
 * with {@see FulfilmentCapacityExhausted} if a bounded `fulfilment_capacity`
 * would be exceeded. Unbounded (`fulfilment_capacity === null`) never
 * refuses. Idempotent per allocation: the 1:1 database constraint on
 * `order_item_allocation_id` means a retried call returns the existing row
 * rather than erroring, the same catch-and-requery pattern {@see
 * AccrueSupplierPayable} already uses.
 */
class RecordSupplierFulfilmentCommitment
{
    public function __construct(
        protected DatabaseManager $database,
        protected RecordAuditLog $audit,
    ) {}

    public function forAllocation(OrderItemAllocation $allocation): SupplierFulfilmentCommitment
    {
        if ($allocation->supplier_offer_id === null) {
            throw new LogicException('Only a Supplier offer allocation can carry a fulfilment commitment.');
        }

        $existing = SupplierFulfilmentCommitment::query()
            ->where('order_item_allocation_id', $allocation->id)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return $this->database->transaction(function () use ($allocation) {
                /** @var SupplierOffer $offer */
                $offer = SupplierOffer::query()->lockForUpdate()->findOrFail($allocation->supplier_offer_id);

                if ($offer->fulfilment_capacity !== null) {
                    $committed = (int) SupplierFulfilmentCommitment::query()
                        ->where('supplier_offer_id', $offer->id)
                        ->whereIn('status', array_map(
                            fn (FulfilmentCommitmentStatus $status) => $status->value,
                            SupplierFulfilmentCommitment::nonTerminalStatuses(),
                        ))
                        ->sum('quantity');

                    if ($committed + $allocation->quantity > $offer->fulfilment_capacity) {
                        throw FulfilmentCapacityExhausted::forOffer($offer, $committed, $allocation->quantity);
                    }
                }

                $commitment = SupplierFulfilmentCommitment::create([
                    'order_item_allocation_id' => $allocation->id,
                    'supplier_offer_id' => $offer->id,
                    'quantity' => $allocation->quantity,
                    'due_at' => $this->dueAt($offer),
                    'confirmation_due_at' => CarbonImmutable::now()->addHours(
                        (int) config('supplier.fulfilment_confirmation_deadline_hours', 48),
                    ),
                ]);

                $commitment->recordStatusChange(
                    null,
                    FulfilmentCommitmentStatus::AwaitingConfirmation,
                    new StatusChange(reason: 'Allocated to a non-ready-stock offer.'),
                    ['source' => SupplierStatusChangeSource::System],
                );

                $this->audit->handle(new AuditEntry(
                    action: 'supplier_fulfilment_commitment.created',
                    auditableType: SupplierFulfilmentCommitment::class,
                    auditableId: $commitment->id,
                    after: [
                        'allocation' => $allocation->public_id,
                        'offer' => $offer->public_id,
                        'quantity' => $commitment->quantity,
                        'due_at' => $commitment->due_at?->toIso8601String(),
                    ],
                    accountId: $offer->supplier_id,
                    module: PermissionModule::Order->value,
                ));

                return $commitment;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $racedWith = SupplierFulfilmentCommitment::query()
                ->where('order_item_allocation_id', $allocation->id)
                ->first();

            if ($racedWith === null) {
                throw $exception;
            }

            return $racedWith;
        }
    }

    /**
     * When the Supplier is due to have this ready -- the offer's own
     * expected-availability date when it declared one, else a figure worked
     * out from its lead time, else unknown.
     */
    protected function dueAt(SupplierOffer $offer): ?CarbonImmutable
    {
        if ($offer->expected_availability_at !== null) {
            return $offer->expected_availability_at;
        }

        if ($offer->lead_time_days !== null) {
            return CarbonImmutable::now()->addDays($offer->lead_time_days);
        }

        return null;
    }
}
