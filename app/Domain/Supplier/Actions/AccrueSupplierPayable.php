<?php

namespace App\Domain\Supplier\Actions;

use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\OrderItemAllocation;
use App\Domain\Supplier\Enums\PayableChangeSource;
use App\Domain\Supplier\Enums\PayableStatus;
use App\Domain\Supplier\Models\SupplierPayable;
use App\Support\StatusHistory\StatusChange;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use LogicException;

/**
 * One pending Supplier payable for one allocated order line, accrued the
 * moment the order is placed (D25, P13-22).
 *
 * A claim, not money: it credits no wallet and creates nothing withdrawable —
 * see {@see SupplierPayable}. Called from inside the same transaction that
 * writes the order line, so a payable and its line are never one without the
 * other. Idempotent on the order line: `order_item_id` is unique, and the
 * order-placement actions this is called from already return the existing
 * order on a retried request, so a second call for the same line cannot
 * happen — the unique index is the backstop, not the primary guard.
 */
class AccrueSupplierPayable
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    public function handle(OrderItem $item): SupplierPayable
    {
        $rate = $item->supplier_rate;
        $quantity = $item->supplier_allocated_quantity;

        if (! $item->isSupplierBacked() || $rate === null || $quantity === null) {
            throw new LogicException('Only a Supplier-backed order line accrues a Supplier payable.');
        }

        $key = 'supplier-payable:'.$item->public_id;
        $existing = $this->byKey($key);

        if ($existing !== null) {
            return $existing;
        }

        try {
            return $this->database->transaction(function () use ($item, $key, $rate, $quantity) {
                $gross = $rate->multipliedBy($quantity);

                $payable = SupplierPayable::create([
                    'supplier_id' => $item->supplier_id,
                    'order_id' => $item->order_id,
                    'order_item_id' => $item->id,
                    'supplier_offer_id' => $item->supplier_offer_id,
                    'supplier_offer_price_change_id' => $item->supplier_offer_price_change_id,
                    'quantity' => $quantity,
                    'supplier_rate' => $rate,
                    'gross_amount' => $gross,
                    'currency_code' => $item->supplier_currency_code,
                    'status' => PayableStatus::Pending->value,
                    'triggering_event' => 'order_placed',
                    'idempotency_key' => $key,
                ]);

                $payable->recordStatusChange(
                    null,
                    PayableStatus::Pending,
                    StatusChange::bySystem(reason: 'Order placed; Supplier line allocated.'),
                    ['source' => PayableChangeSource::System],
                );

                $this->audit->handle(new AuditEntry(
                    action: 'supplier_payable.created',
                    auditableType: SupplierPayable::class,
                    auditableId: $payable->id,
                    after: [
                        'order_item' => $item->public_id,
                        'quantity' => $payable->quantity,
                        'gross_amount' => $gross->toDecimal(),
                        'currency' => $payable->currency_code,
                    ],
                    accountId: $item->supplier_id,
                    module: PermissionModule::SupplierPayable->value,
                ));

                return $payable;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $racedWith = $this->byKey($key);

            if ($racedWith === null) {
                throw $exception;
            }

            return $racedWith;
        }
    }

    /**
     * The payable one staff source allocation makes Feriwala owe.
     *
     * Same claim, same shape, same immutability — what differs is which
     * snapshot it answers to. A placement-time payable matches the order
     * line's own frozen `supplier_*` columns; this one matches the
     * {@see OrderItemAllocation}, which is the only snapshot that can change
     * when staff move a line to a different Supplier. The database enforces
     * whichever applies.
     *
     * The amount is the batch's rule exactly — allocated quantity times the
     * **snapshotted** Supplier Rate, in exact flat Taka (D26), never the
     * offer's current rate, so a repricing after allocation cannot restate
     * what was agreed.
     *
     * Idempotent on the allocation: `order_item_allocation_id` is unique
     * wherever it is set, and the key is the allocation's own public id.
     *
     * @param  string  $event  `staff_allocation` or `reallocation`
     *
     * @throws LogicException for a warehouse allocation, which owes nobody
     */
    public function forAllocation(OrderItemAllocation $allocation, string $event = 'staff_allocation'): SupplierPayable
    {
        if (! $allocation->createsSupplierPayable()) {
            throw new LogicException('A Central Warehouse allocation never creates a Supplier payable.');
        }

        $key = 'supplier-payable:allocation:'.$allocation->public_id;
        $existing = $this->byKey($key);

        if ($existing !== null) {
            return $existing;
        }

        try {
            return $this->database->transaction(function () use ($allocation, $key, $event) {
                $rate = $allocation->unit_cost;
                $gross = $rate->multipliedBy($allocation->quantity);

                $payable = SupplierPayable::create([
                    'supplier_id' => $allocation->supplier_id,
                    'order_id' => $allocation->order_id,
                    'order_item_id' => $allocation->order_item_id,
                    'order_item_allocation_id' => $allocation->id,
                    'supplier_offer_id' => $allocation->supplier_offer_id,
                    'supplier_offer_price_change_id' => $allocation->supplier_offer_price_change_id,
                    'quantity' => $allocation->quantity,
                    'supplier_rate' => $rate,
                    'gross_amount' => $gross,
                    'currency_code' => $allocation->currency_code,
                    'status' => PayableStatus::Pending->value,
                    'triggering_event' => $event,
                    'idempotency_key' => $key,
                ]);

                $payable->recordStatusChange(
                    null,
                    PayableStatus::Pending,
                    new StatusChange(
                        actorId: $allocation->allocated_by,
                        reason: $event === 'reallocation'
                            ? 'Line reallocated to this Supplier.'
                            : 'Line allocated to this Supplier.',
                    ),
                    ['source' => PayableChangeSource::Staff],
                );

                $this->audit->handle(new AuditEntry(
                    action: 'supplier_payable.created',
                    actorId: $allocation->allocated_by,
                    auditableType: SupplierPayable::class,
                    auditableId: $payable->id,
                    after: [
                        'order_item' => $allocation->orderItem->public_id,
                        'allocation' => $allocation->public_id,
                        'quantity' => $payable->quantity,
                        'supplier_rate' => $rate->toDecimal(),
                        'gross_amount' => $gross->toDecimal(),
                        'currency' => $payable->currency_code,
                        'triggering_event' => $event,
                    ],
                    accountId: $allocation->supplier_id,
                    module: PermissionModule::SupplierPayable->value,
                ));

                return $payable;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $racedWith = $this->byKey($key);

            if ($racedWith === null) {
                throw $exception;
            }

            return $racedWith;
        }
    }

    protected function byKey(string $key): ?SupplierPayable
    {
        return SupplierPayable::query()->where('idempotency_key', $key)->first();
    }
}
