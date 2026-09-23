<?php

namespace App\Domain\Supplier\Actions;

use App\Domain\Supplier\Enums\StockUpdateStatus;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierOffer;
use App\Domain\Supplier\Models\SupplierStockMovement;
use App\Domain\Supplier\Models\SupplierStockUpdate;
use InvalidArgumentException;

/**
 * A Supplier's own proposed availability change for one of its offers (D25,
 * P13-15). A Supplier only ever **submits** — the quantity in
 * `supplier_offer_stock` moves only once staff approves it through
 * {@see DecideSupplierStockUpdate}, which is what actually records a
 * {@see SupplierStockMovement}.
 */
class SubmitSupplierStockUpdate
{
    public function handle(Supplier $supplier, SupplierOffer $offer, int $requestedQuantity, ?string $note = null): SupplierStockUpdate
    {
        if ($offer->supplier_id !== $supplier->id) {
            throw new InvalidArgumentException('This offer does not belong to this Supplier.');
        }

        if ($requestedQuantity < 0) {
            throw new InvalidArgumentException('Quantity cannot be negative.');
        }

        return $offer->stockUpdates()->create([
            'requested_quantity' => $requestedQuantity,
            'status' => StockUpdateStatus::Pending,
            'note' => $note,
        ]);
    }
}
