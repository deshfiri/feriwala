<?php

namespace App\Domain\Supplier\Actions;

use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Supplier\Models\SupplierOffer;
use App\Domain\Supplier\Models\SupplierOfferStock;
use App\Domain\Supplier\Models\SupplierStockMovement;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Staff setting a Supplier offer's availability directly, without a Supplier
 * submission behind it — a stock count correction, say (D25, P13-15).
 *
 * Still an immutable {@see SupplierStockMovement}, sourced `staff_adjustment`
 * rather than `supplier_update_approved`, so the ledger always shows which
 * kind of change moved the number.
 */
class AdjustSupplierOfferStock
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    public function handle(SupplierOffer $offer, int $decidedBy, int $quantity, string $reason): SupplierOfferStock
    {
        if ($quantity < 0) {
            throw new InvalidArgumentException('Quantity cannot be negative.');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('A reason is required for a direct stock adjustment.');
        }

        return $this->database->transaction(function () use ($offer, $decidedBy, $quantity, $reason) {
            /** @var SupplierOfferStock $stock */
            $stock = SupplierOfferStock::query()->lockForUpdate()->firstOrCreate(
                ['supplier_offer_id' => $offer->id],
                ['quantity' => 0],
            );

            $before = $stock->quantity;

            $stock->forceFill(['quantity' => $quantity])->save();

            SupplierStockMovement::create([
                'supplier_offer_id' => $offer->id,
                'quantity_before' => $before,
                'quantity_after' => $quantity,
                'source' => 'staff_adjustment',
                'actor_type' => 'staff',
                'actor_id' => $decidedBy,
                'reason' => $reason,
                'created_at' => now(),
            ]);

            $this->audit->handle(new AuditEntry(
                action: 'supplier_stock.adjusted',
                actorId: $decidedBy,
                auditableType: SupplierOfferStock::class,
                auditableId: $stock->id,
                before: ['quantity' => $before],
                after: ['quantity' => $quantity],
                reason: $reason,
                module: PermissionModule::SupplierStock->value,
            ));

            return $stock->refresh();
        });
    }
}
