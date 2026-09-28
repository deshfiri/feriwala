<?php

namespace App\Domain\Location\Data;

/**
 * What one importer run found and, unless `$problems` is non-empty, did.
 */
readonly class ImportReport
{
    /**
     * @param  list<string>  $problems  human-readable rejections; non-empty means nothing was written
     * @param  array<string, int>  $counts  keyed by BdLocationType value plus 'deactivated'
     */
    public function __construct(
        public string $mode,
        public array $problems,
        public array $counts,
    ) {}

    public function isValid(): bool
    {
        return $this->problems === [];
    }
}
