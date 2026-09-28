<?php

namespace App\Domain\Address\Enums;

use App\Domain\Address\Actions\ArchiveSharedAddress;
use App\Support\StateMachine\TransitionableState;

/**
 * An address's lifecycle: {@see ArchiveSharedAddress} is the only way to
 * reach {@see self::Archived}, via `transitionTo()` — never by assigning the
 * column directly.
 */
enum AddressStatus: string implements TransitionableState
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
