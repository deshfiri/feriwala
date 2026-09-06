<?php

namespace App\Domain\Package\Data;

use App\Domain\Package\Enums\PackageFeature;

/**
 * One limit an account is over, and by how much (D16).
 *
 * The three numbers a user is owed before a downgrade is refused: what they
 * have now, what the smaller package allows, and how many must go. Computing
 * `mustRemove` here rather than in a template means the figure a user is shown
 * is the same one the guard enforces.
 */
readonly class FeatureExcess
{
    public function __construct(
        public PackageFeature $feature,
        public int $current,
        public int $newLimit,
    ) {}

    /**
     * How many must be removed to fit. Never negative.
     */
    public function mustRemove(): int
    {
        return max($this->current - $this->newLimit, 0);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'feature' => $this->feature->value,
            'label' => $this->feature->label(),
            'current' => $this->current,
            'new_limit' => $this->newLimit,
            'must_remove' => $this->mustRemove(),
        ];
    }
}
