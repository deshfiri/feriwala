<?php

namespace App\Domain\Package\Data;

use App\Domain\Package\Enums\PackageFeature;

/**
 * What stands between an account and a smaller package (D16).
 *
 * Carries the three figures D16 requires a user to be shown — what they have,
 * what the new package allows, and how many must go — rather than a bare
 * refusal. "You cannot downgrade" gives somebody no way to act; "you have 63
 * published products, this package allows 50, unpublish 13" does.
 *
 * `blocking` is deliberately separate from `excess`. A limit can be exceeded on
 * more than one feature at once, and telling a user about the first one only to
 * refuse them again on the next is how a downgrade takes four attempts.
 */
readonly class DowngradeAssessment
{
    /**
     * @param  array<int, FeatureExcess>  $excess  every limit currently exceeded
     */
    public function __construct(
        public bool $isAllowed,
        public array $excess,
    ) {}

    public static function allowed(): self
    {
        return new self(true, []);
    }

    /**
     * @param  array<int, FeatureExcess>  $excess
     */
    public static function blocked(array $excess): self
    {
        return new self(false, $excess);
    }

    /**
     * Whether a specific feature is over the new package's limit.
     */
    public function blocks(PackageFeature $feature): bool
    {
        foreach ($this->excess as $item) {
            if ($item->feature === $feature) {
                return true;
            }
        }

        return false;
    }

    /**
     * How many items must go, across every exceeded limit.
     */
    public function totalToRemove(): int
    {
        $total = 0;

        foreach ($this->excess as $item) {
            $total += $item->mustRemove();
        }

        return $total;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'is_allowed' => $this->isAllowed,
            'total_to_remove' => $this->totalToRemove(),
            'excess' => array_map(fn (FeatureExcess $item) => $item->toArray(), $this->excess),
        ];
    }
}
