<?php

namespace App\Domain\Supplier\Actions;

use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Order\Actions\ConfirmOrderPayment;
use App\Domain\Order\Models\Order;
use App\Domain\Supplier\Enums\PayableChangeSource;
use App\Domain\Supplier\Enums\PayableStatus;
use App\Domain\Supplier\Models\SupplierPayable;
use App\Support\StatusHistory\StatusChange;
use Illuminate\Database\DatabaseManager;

/**
 * Freeze an order's Supplier payables when its stock reservation turns out to
 * be missing or invalid — consistent with the order's own `on_hold` path
 * ({@see ConfirmOrderPayment::hold()}) rather than a
 * Supplier-specific one (D25, P13-22).
 *
 * A held payable is never settled; only a person moves it on, once the order
 * itself is resolved.
 */
class HoldSupplierPayable
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
                ->whereIn('status', [PayableStatus::Pending->value])
                ->lockForUpdate()
                ->get();

            foreach ($payables as $payable) {
                $payable->forceFill(['held_at' => now(), 'hold_reason' => $reason]);

                $payable->transitionWithHistory(
                    PayableStatus::OnHold,
                    StatusChange::bySystem(reason: $reason),
                    ['source' => PayableChangeSource::System],
                );

                $this->audit->handle(new AuditEntry(
                    action: 'supplier_payable.on_hold',
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
