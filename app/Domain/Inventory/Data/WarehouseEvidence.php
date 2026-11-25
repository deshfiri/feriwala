<?php

namespace App\Domain\Inventory\Data;

use App\Domain\Inventory\Queries\PartnerStockWarehouseEvidence;

/**
 * What the records prove about which Banij warehouse holds some Partner units
 * ({@see PartnerStockWarehouseEvidence}).
 */
final readonly class WarehouseEvidence
{
    public const SOURCE_ALLOCATION = 'wholesale_allocation';

    public const SOURCE_RESERVATION = 'wholesale_reservation';

    public const SOURCE_STOCK_HISTORY = 'stock_history';

    /** Staff recorded the warehouse that physically received a return. */
    public const SOURCE_RETURN_RECEIPT = 'return_receipt';

    public const SAFE = 'safe';

    public const AMBIGUOUS = 'ambiguous';

    public const MISSING = 'missing';

    /**
     * @param  list<int>  $warehouses  every warehouse the records name
     * @param  bool  $unproven  some of the units are held somewhere no record names
     * @param  string|null  $source  which record proved it
     */
    public function __construct(
        public array $warehouses,
        public bool $unproven,
        public ?string $source,
    ) {}

    /**
     * `safe` only when exactly one warehouse is named and nothing is unaccounted
     * for; `ambiguous` when several are; `missing` when none is.
     */
    public function classification(): string
    {
        return match (true) {
            count($this->warehouses) > 1 => self::AMBIGUOUS,
            count($this->warehouses) === 1 && ! $this->unproven => self::SAFE,
            count($this->warehouses) === 1 => self::AMBIGUOUS,
            default => self::MISSING,
        };
    }

    /**
     * The proven warehouse, or null unless the evidence is safe.
     */
    public function warehouse(): ?int
    {
        return $this->classification() === self::SAFE ? $this->warehouses[0] : null;
    }
}
