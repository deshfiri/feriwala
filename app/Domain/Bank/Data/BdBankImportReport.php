<?php

namespace App\Domain\Bank\Data;

use App\Domain\Bank\Actions\ImportBdBanks;

/**
 * What one {@see ImportBdBanks} run found and,
 * unless `$problems` is non-empty, did.
 */
readonly class BdBankImportReport
{
    /**
     * @param  list<string>  $problems  human-readable rejections; non-empty means nothing was written
     * @param  array<string, int>  $counts  banks/branches/deactivated_banks/deactivated_branches
     * @param  list<string>  $unmatchedDistricts  distinct source district names no `bd_locations` row resolved for
     */
    public function __construct(
        public string $mode,
        public array $problems,
        public array $counts,
        public array $unmatchedDistricts,
    ) {}

    public function isValid(): bool
    {
        return $this->problems === [];
    }
}
