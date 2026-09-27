<?php

namespace App\Domain\Order\Actions;

use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Inventory\Enums\ReservationKind;
use App\Domain\Inventory\Enums\StockReservationStatus;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\StockReservations;
use App\Domain\Order\Data\AllocationCandidate;
use App\Domain\Order\Enums\AllocationSourceType;
use App\Domain\Order\Enums\AllocationStatus;
use App\Domain\Order\Enums\OrderDeliveryStatus;
use App\Domain\Order\Enums\OrderFulfillmentStatus;
use App\Domain\Order\Exceptions\AllocationRefused;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\OrderItemAllocation;
use App\Domain\Order\Queries\AllocationSourceCandidates;
use App\Domain\Supplier\Actions\AccrueSupplierPayable;
use App\Domain\Supplier\Actions\CancelSupplierPayable;
use App\Domain\Supplier\Models\SupplierOffer;
use App\Models\User;
use App\Support\Concurrency\DistributedLock;
use App\Support\Money\Currency;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Assign one order line to the source a member of staff chose, and everything
 * that follows from it.
 *
 * **The platform does not choose.** {@see AllocationSourceCandidates} reports
 * what every source holds and what it would earn; this takes the one a person
 * picked and commits to it. Nothing here falls back to the cheapest Supplier,
 * and a source that has become unavailable since the panel was rendered is
 * refused rather than quietly swapped — the reason goes back to the staff
 * member so they can choose again from a refreshed list.
 *
 * One line, one source, one reservation, one payable. The no-split rule is
 * unchanged: a source that cannot cover the whole line is not eligible, so an
 * allocation is always for the line's full quantity.
 *
 * ### What one allocation does, atomically
 *
 * Under a per-line distributed lock and inside one transaction: the line is
 * locked, the chosen source re-checked against live availability, the
 * reservation taken, the decision written as an {@see OrderItemAllocation}
 * snapshot, and — for a Supplier source only — the pending payable raised. A
 * Central Warehouse line never creates a Supplier payable.
 *
 * ### Reallocation
 *
 * Moving a line to a different source releases the previous reservation,
 * cancels the previous pending payable, marks the old allocation superseded and
 * points it at its replacement, then allocates the new source — all in the same
 * transaction. There is never a moment with two active reservations or two live
 * payables for one line, and the database refuses it independently: a partial
 * unique index allows one `active` allocation per line, and one live payable
 * per line.
 *
 * Idempotent. Re-confirming the source a line already holds returns the
 * existing allocation and touches nothing, so a double-clicked confirmation or
 * a retried request cannot reserve twice or owe twice.
 */
class AllocateOrderLineSource
{
    public function __construct(
        protected AllocationSourceCandidates $candidates,
        protected StockReservations $reservations,
        protected AccrueSupplierPayable $payables,
        protected CancelSupplierPayable $cancellations,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
        protected DistributedLock $lock,
    ) {}

    /**
     * @param  string  $sourceId  a warehouse's or Supplier offer's **public** id
     *
     * @throws AllocationRefused
     */
    public function handle(
        OrderItem $line,
        AllocationSourceType $sourceType,
        string $sourceId,
        User $actor,
        string $reason,
    ): OrderItemAllocation {
        if (trim($reason) === '') {
            throw AllocationRefused::because('A reason is required and is recorded against this allocation.');
        }

        return $this->lock->run(
            key: 'order-line:allocate:'.$line->id,
            callback: fn () => $this->allocate($line, $sourceType, $sourceId, $actor, trim($reason)),
            ttlSeconds: 30,
            waitSeconds: 10,
        );
    }

    /**
     * @throws AllocationRefused
     */
    protected function allocate(
        OrderItem $line,
        AllocationSourceType $sourceType,
        string $sourceId,
        User $actor,
        string $reason,
    ): OrderItemAllocation {
        return $this->database->transaction(function () use ($line, $sourceType, $sourceId, $actor, $reason) {
            /** @var OrderItem $locked */
            $locked = OrderItem::query()->lockForUpdate()->findOrFail($line->id);
            $order = $locked->order()->lockForUpdate()->firstOrFail();

            $current = OrderItemAllocation::query()
                ->where('order_item_id', $locked->id)
                ->where('status', AllocationStatus::Active)
                ->lockForUpdate()
                ->first();

            // Re-confirming what the line already holds changes nothing. The
            // panel's confirm button is double-clickable and the request is
            // retryable; neither may reserve twice or owe twice.
            if ($current !== null && $this->isSameSource($current, $sourceType, $sourceId)) {
                return $current;
            }

            if ($current !== null && ! $this->canReallocate($order)) {
                throw AllocationRefused::because(
                    'This line has already been picked or dispatched and can no longer be reallocated.',
                );
            }

            // Re-read live, inside the lock. The panel is a view and can be
            // minutes old; this is the decision.
            $candidate = $this->candidateFor($locked, $sourceType, $sourceId);

            if (! $candidate->isEligible) {
                throw AllocationRefused::because(
                    $candidate->ineligibleReason ?? 'This source can no longer serve this line.',
                );
            }

            if ($current !== null) {
                $this->standDown($current, $actor, $reason);
            }

            return $this->commitTo($locked, $candidate, $current, $actor, $reason);
        });
    }

    /**
     * Give up the source a line was on, so a different one can take it.
     *
     * Reservation released and payable cancelled together — a released
     * reservation with a live payable would owe a Supplier for stock nobody is
     * holding, and the reverse would hold stock nobody is paying for.
     */
    protected function standDown(OrderItemAllocation $current, User $actor, string $reason): void
    {
        $reservation = $current->reservation;

        if ($reservation !== null && $reservation->status === StockReservationStatus::Active) {
            $this->reservations->release($reservation, 'Reallocated: '.$reason, $actor->id);
        }

        $payable = $current->payable;

        if ($payable !== null) {
            $this->cancellations->cancelOne($payable, 'Line reallocated to another source: '.$reason, $actor->id);
        }

        $current->forceFill([
            'status' => AllocationStatus::Superseded,
            'released_by' => $actor->id,
            'released_at' => now(),
            'release_reason' => $reason,
        ])->save();
    }

    /**
     * Reserve the chosen source and write the decision.
     *
     * @throws AllocationRefused
     */
    protected function commitTo(
        OrderItem $line,
        AllocationCandidate $candidate,
        ?OrderItemAllocation $superseded,
        User $actor,
        string $reason,
    ): OrderItemAllocation {
        $currency = Currency::from($line->currency_code);
        $quantity = (int) $line->quantity;
        $key = 'order-line-allocation:'.$line->public_id.':'.Str::lower((string) Str::ulid());

        $offer = $candidate->sourceType === AllocationSourceType::SupplierOffer
            ? SupplierOffer::query()->where('public_id', $candidate->sourceId)->firstOrFail()
            : null;

        $reservation = $this->reserve($line, $candidate, $offer, $quantity, $key);

        try {
            $allocation = OrderItemAllocation::create([
                'order_id' => $line->order_id,
                'order_item_id' => $line->id,
                'source_type' => $candidate->sourceType,
                'warehouse_id' => $offer === null
                    ? Warehouse::query()->where('public_id', $candidate->sourceId)->value('id')
                    : null,
                'supplier_id' => $offer?->supplier_id,
                'supplier_offer_id' => $offer?->id,
                'supplier_offer_price_change_id' => $offer === null
                    ? null
                    : $this->currentPriceVersionId($offer),
                'stock_reservation_id' => $reservation->id,
                'quantity' => $quantity,
                'unit_cost' => $candidate->unitCost,
                'platform_rate' => $candidate->platformRate,
                'expected_margin' => $candidate->expectedMargin,
                'currency_code' => $currency->value,
                'status' => AllocationStatus::Active,
                'allocated_by' => $actor->id,
                'allocated_at' => now(),
                'allocation_reason' => $reason,
                'idempotency_key' => $key,
            ]);
        } catch (UniqueConstraintViolationException) {
            // The partial unique index refused a second active allocation:
            // another request won this line while we were reserving. Give the
            // units straight back rather than stranding them.
            $this->reservations->release($reservation, 'Allocation lost a race for this line.', $actor->id);

            throw AllocationRefused::because('This line was allocated by someone else a moment ago.');
        }

        $superseded?->forceFill(['superseded_by_allocation_id' => $allocation->id])->save();

        if ($allocation->createsSupplierPayable()) {
            $this->payables->forAllocation(
                $allocation,
                $superseded === null ? 'staff_allocation' : 'reallocation',
            );
        }

        $this->audit->handle(new AuditEntry(
            action: $superseded === null ? 'order_line.allocated' : 'order_line.reallocated',
            actorId: $actor->id,
            auditableType: OrderItemAllocation::class,
            auditableId: $allocation->id,
            before: $superseded === null ? null : [
                'source_type' => $superseded->source_type->value,
                'allocation' => $superseded->public_id,
            ],
            after: [
                'source_type' => $allocation->source_type->value,
                'source' => $candidate->sourceId,
                'quantity' => $quantity,
                'unit_cost' => $allocation->unit_cost->toDecimal(),
                'expected_margin' => $allocation->expected_margin->toDecimal(),
                'currency' => $currency->value,
                'reservation' => $reservation->reference,
            ],
            reason: $reason,
            module: PermissionModule::Order->value,
        ));

        return $allocation;
    }

    /**
     * @throws AllocationRefused
     */
    protected function reserve(
        OrderItem $line,
        AllocationCandidate $candidate,
        ?SupplierOffer $offer,
        int $quantity,
        string $reference,
    ): StockReservation {
        $account = $line->order->businessAccount;
        $kind = ReservationKind::OnlinePayment;

        if ($offer !== null) {
            return $this->reservations->reserveFromSupplier($offer, $quantity, $kind, $reference, $account);
        }

        return $this->reservations->reserve(
            $line->product,
            $line->product_variant_id === null ? null : $line->variant,
            $quantity,
            $kind,
            $reference,
            $account,
        );
    }

    /**
     * The rate version in force for this offer, which the payable and the
     * snapshot must both answer to.
     *
     * @throws AllocationRefused when the offer has no priced history to point at
     */
    protected function currentPriceVersionId(SupplierOffer $offer): int
    {
        $version = $offer->priceHistory()
            ->where('effective_from', '<=', now())
            ->latest('effective_from')
            ->latest('id')
            ->first();

        if ($version === null) {
            throw AllocationRefused::because('This Supplier offer has no effective rate to allocate against.');
        }

        return $version->id;
    }

    protected function isSameSource(OrderItemAllocation $allocation, AllocationSourceType $type, string $sourceId): bool
    {
        if ($allocation->source_type !== $type) {
            return false;
        }

        return $type === AllocationSourceType::Warehouse
            ? $allocation->warehouse?->public_id === $sourceId
            : $allocation->offer?->public_id === $sourceId;
    }

    /**
     * @throws AllocationRefused
     */
    protected function candidateFor(OrderItem $line, AllocationSourceType $type, string $sourceId): AllocationCandidate
    {
        foreach ($this->candidates->forLine($line) as $candidate) {
            if ($candidate->sourceType === $type && $candidate->sourceId === $sourceId) {
                return $candidate;
            }
        }

        throw AllocationRefused::because('That source does not serve this product.');
    }

    /**
     * Whether this order is still early enough to change its mind.
     *
     * Reallocation is allowed only before picking, dispatch or any other
     * irreversible fulfilment step. **Today nothing can refuse it**: both
     * enums are single-valued stubs — `unfulfilled` and `not_shipped` — because
     * picking and dispatch states do not exist yet (P6.B).
     *
     * Written as an exhaustive `match` rather than a comparison precisely
     * because of that. A comparison would silently keep returning true when
     * P6.B adds `Picking` or `Dispatched`; this fails the build until someone
     * states what those mean for reallocation, which is the decision that
     * should not be made by omission.
     */
    protected function canReallocate(Order $order): bool
    {
        return match ($order->fulfillment_status) {
            OrderFulfillmentStatus::Unfulfilled => $this->isBeforeDispatch($order),
        };
    }

    /**
     * The dispatch half of {@see canReallocate()}, exhaustive for the same
     * reason: a courier handover is the point after which the source can no
     * longer change.
     */
    protected function isBeforeDispatch(Order $order): bool
    {
        return match ($order->delivery_status) {
            OrderDeliveryStatus::NotShipped => true,
        };
    }
}
