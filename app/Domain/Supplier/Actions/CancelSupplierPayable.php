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
}
