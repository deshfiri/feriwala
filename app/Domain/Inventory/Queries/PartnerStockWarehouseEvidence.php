<?php

namespace App\Domain\Inventory\Queries;

use App\Domain\Inventory\Data\WarehouseEvidence;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Order\Enums\AllocationSourceType;
use App\Domain\Order\Enums\AllocationStatus;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\OrderItemAllocation;
use Illuminate\Support\Facades\DB;

/**
 * Where a Partner's units are physically held, proven from the wholesale
 * purchase that gave them -- never assumed.
 *
 * A wholesale line's units left central stock from the warehouse that filled
 * it, and stayed there: Partners hold no stock themselves. The line names that
 * warehouse in one of two authoritative ways:
 *
 *  - its active order-line allocations, when staff assigned it a source; or
 *  - the central-stock reservation it was placed with -- the reserved stock
 *    item's warehouse, or that of the allocation the reservation drew on.
 *
 * Anything else proves nothing. A Supplier-sourced line names no Banij
 * warehouse; a released or superseded allocation is not where the units went.
 * There is no fallback to a default or "the only warehouse there is".
 */
class PartnerStockWarehouseEvidence
{
    /**
     * What one wholesale purchase line proves.
     */
    public function forPurchaseLine(int $orderItemId): WarehouseEvidence
    {
        $allocations = OrderItemAllocation::query()
            ->where('order_item_id', $orderItemId)
            ->where('status', AllocationStatus::Active->value)
            ->get(['source_type', 'warehouse_id']);

        if ($allocations->isNotEmpty()) {
            $warehouses = [];
            $unproven = false;

            foreach ($allocations as $allocation) {
                if ($allocation->source_type === AllocationSourceType::Warehouse && $allocation->warehouse_id !== null) {
                    $warehouses[] = $allocation->warehouse_id;
                } else {
                    $unproven = true;
                }
            }

            return new WarehouseEvidence(array_values(array_unique($warehouses)), $unproven, WarehouseEvidence::SOURCE_ALLOCATION);
        }

        /** @var OrderItem|null $line */
        $line = OrderItem::query()->find($orderItemId, ['id', 'stock_reservation_id']);

        if ($line === null || $line->stock_reservation_id === null) {
            return new WarehouseEvidence([], true, null);
        }

        /** @var StockReservation|null $reservation */
        $reservation = StockReservation::query()->with(['item', 'allocation.item'])->find($line->stock_reservation_id);
        $warehouseId = $reservation === null ? null : ($reservation->item->warehouse_id ?? $reservation->allocation->item->warehouse_id ?? null);

        return $warehouseId === null
            ? new WarehouseEvidence([], true, null)
            : new WarehouseEvidence([(int) $warehouseId], false, WarehouseEvidence::SOURCE_RESERVATION);
    }

    /**
     * What every purchase that ever credited one Partner stock row proves
     * together -- the only evidence for units already on the shelf before lots
     * recorded their purchases one by one.
     */
    public function forStockRow(int $partnerStockItemId): WarehouseEvidence
    {
        $lines = DB::table('partner_stock_movements')
            ->where('partner_stock_item_id', $partnerStockItemId)
            ->where('type', 'purchase_received')
            ->where('source_type', 'order_item')
            ->pluck('source_id');

        if ($lines->isEmpty()) {
            return new WarehouseEvidence([], true, null);
        }

        $warehouses = [];
        $unproven = false;

        foreach ($lines as $lineId) {
            $evidence = $this->forPurchaseLine((int) $lineId);
            $warehouses = [...$warehouses, ...$evidence->warehouses];
            $unproven = $unproven || $evidence->unproven;
        }

        return new WarehouseEvidence(array_values(array_unique($warehouses)), $unproven, WarehouseEvidence::SOURCE_STOCK_HISTORY);
    }
}
