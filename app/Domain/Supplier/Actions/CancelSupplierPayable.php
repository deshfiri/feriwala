<?php

namespace App\Domain\Supplier\Actions;

use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Order\Actions\CancelUnpaidOrder;
use App\Domain\Order\Models\Order;
use App\Domain\Supplier\Enums\PayableChangeSource;
use App\Domain\Supplier\Enums\PayableStatus;
use App\Domain\Supplier\Models\SupplierPayable;
use App\Support\StatusHistory\StatusChange;
use Illuminate\Database\DatabaseManager;

/**
 * Cancel every unpaid pending Supplier payable an order still carries, when
 * the order itself is cancelled or its payment window expires (D25, P13-22).
 *
 * Called from {@see CancelUnpaidOrder} in the same
 * transaction that releases the order's stock — never creates a wallet
 * credit, because a pending payable was never earned. Only `Pending` moves:
 * one already `Eligible`, `Settled` or otherwise reversed stands, because by
 * definition an unpaid order's payable has not reached those states, and a
 * repeat call finds nothing left to cancel.
 */
class CancelSupplierPayable
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    public function handle(Order $order, string $reason): void
    {
        $this->database->transaction(function () use ($order, $reason) {
            $payables = SupplierPayable::query()
                ->where('order_id', $order->id)
                ->where('status', PayableStatus::Pending->value)
                ->lockForUpdate()
                ->get();

            foreach ($payables as $payable) {
                $payable->forceFill(['cancelled_at' => now()]);

                $payable->transitionWithHistory(
                    PayableStatus::Cancelled,
                    StatusChange::bySystem(reason: $reason),
                    ['source' => PayableChangeSource::System],
                );

                $this->audit->handle(new AuditEntry(
                    action: 'supplier_payable.cancelled',
                    auditableType: SupplierPayable::class,
                    auditableId: $payable->id,
                    reason: $reason,
                    accountId: $payable->supplier_id,
                    module: PermissionModule::SupplierPayable->value,
                ));
            }
        });
    }

    /**
     * Cancel one payable, because the allocation it was raised for has been
     * replaced.
     *
     * The same transition, history row and audit entry as cancelling a whole
     * order's payables — the only difference is the scope, since a reallocation
     * takes back exactly one line's obligation and must leave the rest of the
     * order's alone.
     *
     * Idempotent: a payable that is no longer `Pending` has either already been
     * cancelled by a retry or has been earned, and neither is this method's to
     * undo. The caller holds the transaction.
     */
    public function cancelOne(SupplierPayable $payable, string $reason, ?int $cancelledBy = null): bool
    {
        /** @var SupplierPayable|null $locked */
        $locked = SupplierPayable::query()->lockForUpdate()->find($payable->id);

        if ($locked === null || $locked->status !== PayableStatus::Pending) {
            return false;
        }

        $locked->forceFill(['cancelled_at' => now()]);

        $locked->transitionWithHistory(
            PayableStatus::Cancelled,
            new StatusChange(actorId: $cancelledBy, reason: $reason),
            ['source' => $cancelledBy === null ? PayableChangeSource::System : PayableChangeSource::Staff],
        );

        $this->audit->handle(new AuditEntry(
            action: 'supplier_payable.cancelled',
            actorId: $cancelledBy,
            auditableType: SupplierPayable::class,
            auditableId: $locked->id,
            reason: $reason,
            accountId: $locked->supplier_id,
            module: PermissionModule::SupplierPayable->value,
        ));

        $payable->setRawAttributes($locked->getAttributes(), sync: true);

        return true;
    }
}
