<?php

namespace App\Domain\Damage\Data;

use App\Domain\Damage\Enums\DamageCondition;

/**
 * What somebody says is damaged and why they say so (D35).
 *
 * Only a claim: the quantity is checked against what was sold, the value is
 * worked out from the line and never from anything here, and the cause and who
 * bears the loss are decided later by people who can see the goods.
 */
final class DamageReport
{
    /**
     * @param  list<array{type?: string, reference: string, note?: string|null}>  $evidence  references to the evidence, not the files themselves
     * @param  bool  $parcelExpected  whether the item is being sent back to Banij (a delivered report only)
     */
    public function __construct(
        public readonly int $quantity,
        public readonly DamageCondition $condition,
        public readonly ?string $description = null,
        public readonly array $evidence = [],
        public readonly bool $parcelExpected = false,
        public readonly ?string $idempotencyKey = null,
    ) {}
}
