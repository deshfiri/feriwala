<?php

namespace App\Domain\Inventory;

use Closure;
use Illuminate\Container\Attributes\Scoped;

/**
 * Which Banij warehouse a Partner-stock hold must take its units from, for the
 * duration of one order's reservation.
 *
 * Placing a Storefront order chooses the one warehouse that can fill it
 * ({@see Queries\PartnerStockSingleWarehouse}) and reserves inside this scope;
 * the cost lots then draw only that warehouse's lots, so the reservation -- and
 * through it the shipment and its label -- is frozen to one warehouse. Outside a
 * scope, holds draw first in, first out across every lot, as before.
 *
 * One instance per request or job, always restored, so a nested or failed
 * reservation never leaves the next one constrained.
 */
#[Scoped]
class PartnerLotWarehouseScope
{
    protected ?int $warehouseId = null;

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function within(?int $warehouseId, Closure $callback): mixed
    {
        $previous = $this->warehouseId;
        $this->warehouseId = $warehouseId;

        try {
            return $callback();
        } finally {
            $this->warehouseId = $previous;
        }
    }

    public function current(): ?int
    {
        return $this->warehouseId;
    }
}
