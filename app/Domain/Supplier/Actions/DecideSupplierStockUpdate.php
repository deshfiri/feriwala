<?php

namespace App\Domain\Supplier\Actions;

use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Supplier\Enums\StockUpdateStatus;
use App\Domain\Supplier\Models\SupplierOfferStock;
use App\Domain\Supplier\Models\SupplierStockMovement;
use App\Domain\Supplier\Models\SupplierStockUpdate;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Staff approval (or rejection) of a Supplier's proposed availability change
 * (D25, P13-15).
 *
 * Approval is the only thing that moves `supplier_offer_stock.quantity`, and
 * it always writes an immutable {@see SupplierStockMovement} alongside it —
 * the applied-change ledger this domain's stock foundation is built on,
 * mirroring how central `stock_movements` already works. Central warehouse
 * stock is never read or written here; the two stay distinguishable sources,
 * exactly as required.
 */
class DecideSupplierStockUpdate
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    public function approve(SupplierStockUpdate $update, int $decidedBy, ?string $note = null): SupplierStockUpdate
    {
        return $this->database->transaction(function () use ($update, $decidedBy, $note) {
            /** @var SupplierStockUpdate $locked */
            $locked = SupplierStockUpdate::query()->lockForUpdate()->findOrFail($update->id);

            if ($locked->status !== StockUpdateStatus::Pending) {
                throw new InvalidArgumentException('This availability update has already been decided.');
            }

            /** @var SupplierOfferStock $stock */
            $stock = SupplierOfferStock::query()->lockForUpdate()->firstOrCreate(
                ['supplier_offer_id' => $locked->supplier_offer_id],
                ['quantity' => 0],
            );

            $before = $stock->quantity;
            $after = $locked->requested_quantity;

            $stock->forceFill(['quantity' => $after])->save();

            SupplierStockMovement::create([
                'supplier_offer_id' => $locked->supplier_offer_id,
                'supplier_stock_update_id' => $locked->id,
                'quantity_before' => $before,
                'quantity_after' => $after,
                'source' => 'supplier_update_approved',
                'actor_type' => 'staff',
                'actor_id' => $decidedBy,
                'reason' => $note,
                'created_at' => now(),
            ]);

            $locked->forceFill([
                'status' => StockUpdateStatus::Approved,
                'decided_by' => $decidedBy,
                'decided_at' => now(),
                'decision_note' => $note,
            ])->save();

            $this->audit->handle(new AuditEntry(
                action: 'supplier_stock.approved',
                actorId: $decidedBy,
                auditableType: SupplierStockUpdate::class,
                auditableId: $locked->id,
                before: ['quantity' => $before],
                after: ['quantity' => $after],
                note: $note,
                module: PermissionModule::SupplierStock->value,
            ));

            return $locked->refresh();
        });
    }

    public function reject(SupplierStockUpdate $update, int $decidedBy, ?string $note = null): SupplierStockUpdate
    {
        /** @var SupplierStockUpdate $locked */
        $locked = SupplierStockUpdate::query()->lockForUpdate()->findOrFail($update->id);

        if ($locked->status !== StockUpdateStatus::Pending) {
            throw new InvalidArgumentException('This availability update has already been decided.');
        }

        $locked->forceFill([
            'status' => StockUpdateStatus::Rejected,
            'decided_by' => $decidedBy,
            'decided_at' => now(),
            'decision_note' => $note,
        ])->save();

        $this->audit->handle(new AuditEntry(
            action: 'supplier_stock.rejected',
            actorId: $decidedBy,
            auditableType: SupplierStockUpdate::class,
            auditableId: $locked->id,
            note: $note,
            module: PermissionModule::SupplierStock->value,
        ));

        return $locked->refresh();
    }
}
