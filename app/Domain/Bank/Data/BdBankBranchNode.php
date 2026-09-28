<?php

namespace App\Domain\Bank\Data;

/**
 * One parsed, not-yet-written branch row from `bangladesh_bank_branches_flat.json`.
 */
readonly class BdBankBranchNode
{
    public function __construct(
        public string $bankCode,
        public string $routingNumber,
        public string $name,
        public string $slug,
        public string $districtSourceName,
        public ?int $districtLocationId,
        public ?string $branchCode,
        public ?string $originalBranchCode,
        public ?string $swiftCode,
        public ?string $address,
        public ?string $telephone,
        public ?string $email,
        public ?string $fax,
        public string $source,
        public string $sourceStatus,
    ) {}
}
