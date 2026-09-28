<?php

namespace App\Domain\Location\Data;

use App\Domain\Location\Actions\ImportBdLocations;
use App\Domain\Location\Enums\BdLocationType;

/**
 * One directory node, EN and BN already matched by natural key — the shape
 * {@see ImportBdLocations} upserts from.
 */
readonly class BdLocationNode
{
    public function __construct(
        public BdLocationType $type,
        public string $sourceId,
        public ?string $sourceParentId,
        public string $nameEn,
        public string $nameBn,
    ) {}
}
