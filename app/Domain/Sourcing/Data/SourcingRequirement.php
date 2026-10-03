<?php

namespace App\Domain\Sourcing\Data;

/**
 * What an order line requires of its fulfilment: the sourcing group and the
 * canonical product/variation any source must map to.
 */
final readonly class SourcingRequirement
{
    public function __construct(
        public int $groupId,
        public int $canonicalProductId,
        public ?int $canonicalVariantId,
    ) {}
}
