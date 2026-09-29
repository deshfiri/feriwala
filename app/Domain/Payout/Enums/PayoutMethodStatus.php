<?php

namespace App\Domain\Payout\Enums;

use App\Domain\Payout\Actions\ArchivePayoutMethod;
use App\Support\StateMachine\TransitionableState;

/**
 * A payout method's lifecycle: {@see ArchivePayoutMethod}
 * is the only way to reach {@see self::Archived}, via `transitionTo()` — never
 * by assigning the column directly.
 *
 * Never deleted (see the migration's own docblock) — a withdrawal already
 * made against a method keeps its own frozen `payout_snapshot`, so archiving
 * one changes nothing about a past request; it only stops the method being
 * offered for a new one.
 */
enum PayoutMethodStatus: string implements TransitionableState
{
    case Active = 'active';
    case Archived = 'archived';

    /**
     * @return array<int, self>
     */
    public function transitionsTo(): array
    {
        return match ($this) {
            self::Active => [self::Archived],
            self::Archived => [],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Archived => 'Archived',
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Archived;
    }
}
