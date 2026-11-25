<?php

namespace App\Domain\Courier\Data;

use App\Domain\Courier\Queries\ResolveShipmentWarehouse;

/**
 * Which Banij warehouses an order's goods are held in, as
 * {@see ResolveShipmentWarehouse} read them from the order's own stock
 * allocations and reservations.
 */
final readonly class ShipmentWarehouseResolution
{
    /**
     * @param  array<int, int>  $quantities  units per warehouse id
     * @param  bool  $undetermined  some line is held somewhere no warehouse identifies
     */
    public function __construct(
        public array $quantities,
        public bool $undetermined,
    ) {}

    /**
     * The one warehouse every unit leaves from, or null when there is not
     * exactly one -- several warehouses, none, or any unit unaccounted for.
     */
    public function single(): ?int
    {
        if ($this->undetermined || count($this->quantities) !== 1) {
            return null;
        }

        return (int) array_key_first($this->quantities);
    }

    /**
     * Why one parcel cannot leave from one known warehouse, as a safe code, or
     * null when it can. Under the current single-shipment model an order spread
     * over several warehouses is held for staff, never given one return address.
     */
    public function refusal(): ?string
    {
        if (count($this->quantities) > 1) {
            return 'multiple_warehouses';
        }

        return $this->single() === null ? 'warehouse_undetermined' : null;
    }

    public function includes(int $warehouseId): bool
    {
        return isset($this->quantities[$warehouseId]);
    }

    public function quantityFrom(int $warehouseId): int
    {
        return $this->quantities[$warehouseId] ?? 0;
    }
}
