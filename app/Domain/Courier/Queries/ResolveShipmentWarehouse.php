<?php

namespace App\Domain\Courier\Queries;

use App\Domain\Courier\Data\ShipmentWarehouseResolution;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Order\Enums\AllocationSourceType;
use App\Domain\Order\Enums\AllocationStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\OrderItemAllocation;
use Illuminate\Support\Facades\DB;

/**
 * Which Banij warehouse an order's parcel leaves from, read only from what
 * says so with authority. **Never a guess.**
 *
 * For each line, in this order:
 *
 *  1. Its active allocations, when staff assigned it a source. Every one must
 *     be a warehouse allocation; a Supplier source names no Banij warehouse.
 *  2. Otherwise its stock reservation: for central stock, the stock item's
 *     warehouse or that of the allocation it drew from; for a Partner's own
 *     units (D34), the warehouses of the cost lots the hold took them from --
 *     frozen when the order reserved, never worked out again here.
 *
 * A line held any other way -- Supplier stock, or Partner units from a lot whose
 * warehouse is unproven -- leaves the resolution undetermined, and courier
 * booking and the label are refused rather than given a return address nobody
 * can vouch for. No default warehouse, no Storefront address, no fallback.
 */
class ResolveShipmentWarehouse
{
    public function handle(Order $order): ShipmentWarehouseResolution
    {
        $order->loadMissing('items');

        $allocations = OrderItemAllocation::query()
            ->where('order_id', $order->id)
            ->where('status', AllocationStatus::Active->value)
            ->get()
            ->groupBy('order_item_id');

        $quantities = [];
        $undetermined = false;

        foreach ($order->items as $item) {
            $lineAllocations = $allocations->get($item->id);

            if ($lineAllocations !== null && $lineAllocations->isNotEmpty()) {
                foreach ($lineAllocations as $allocation) {
                    /** @var OrderItemAllocation $allocation */
                    if ($allocation->source_type !== AllocationSourceType::Warehouse || $allocation->warehouse_id === null) {
                        $undetermined = true;

                        continue;
                    }

                    $quantities[$allocation->warehouse_id] = ($quantities[$allocation->warehouse_id] ?? 0) + $allocation->quantity;
                }

                continue;
            }

            $reserved = $this->reservedWarehouses($item);

            if ($reserved === null) {
                $undetermined = true;

                continue;
            }

            foreach ($reserved as $warehouseId => $units) {
                $quantities[$warehouseId] = ($quantities[$warehouseId] ?? 0) + $units;
            }
        }

        if ($order->items->isEmpty()) {
            $undetermined = true;
        }

        return new ShipmentWarehouseResolution($quantities, $undetermined);
    }

    /**
     * Units per warehouse that a line's reservation holds, or null when any of
     * them is held somewhere no record names.
     *
     * @return array<int, int>|null
     */
    protected function reservedWarehouses(OrderItem $item): ?array
    {
        if ($item->stock_reservation_id === null) {
            return null;
        }

        /** @var StockReservation|null $reservation */
        $reservation = StockReservation::query()->with(['item', 'allocation.item'])->find($item->stock_reservation_id);

        if ($reservation === null) {
            return null;
        }

        // Central stock names its warehouse directly.
        $central = $reservation->item->warehouse_id ?? $reservation->allocation->item->warehouse_id ?? null;

        if ($central !== null) {
            return [(int) $central => $item->quantity];
        }

        // A Partner's own units are named by the cost lots the hold drew on.
        return $reservation->partner_stock_item_id === null ? null : $this->partnerLotWarehouses($reservation->id, $item->quantity);
    }

    /**
     * The warehouses of the lots one Partner hold took its units from, net of
     * anything given back -- the warehouse frozen when the order reserved, never
     * worked out again from the Product or a default.
     *
     * @return array<int, int>|null
     */
    protected function partnerLotWarehouses(int $reservationId, int $quantity): ?array
    {
        $held = DB::table('partner_stock_lot_movements as movement')
            ->join('partner_stock_cost_lots as lot', 'lot.id', '=', 'movement.partner_stock_cost_lot_id')
            ->where('movement.stock_reservation_id', $reservationId)
            ->whereIn('movement.type', ['allocated', 'released'])
            ->groupBy('lot.id', 'lot.warehouse_id')
            ->havingRaw('-SUM(movement.quantity) > 0')
            ->get(['lot.warehouse_id', DB::raw('-SUM(movement.quantity) AS units')]);

        // Units held without a lot, or from a lot whose warehouse is unproven.
        if ($held->isEmpty() || (int) $held->sum('units') !== $quantity || $held->contains(fn ($row) => $row->warehouse_id === null)) {
            return null;
        }

        $quantities = [];

        foreach ($held as $row) {
            $quantities[(int) $row->warehouse_id] = ($quantities[(int) $row->warehouse_id] ?? 0) + (int) $row->units;
        }

        return $quantities;
    }
}
