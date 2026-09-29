<?php

namespace App\Domain\Order\Enums;

use App\Support\StateMachine\TransitionableState;

/**
 * A confirmed cross-catalogue source link's lifecycle. Never deleted —
 * a link staff later decide was wrong is revoked, not removed, so any
 * allocation already made while it was active stays explicable.
 */
enum ProductSourceLinkStatus: string implements TransitionableState
{
    case Active = 'active';
    case Revoked = 'revoked';

    /**
     * @return array<int, self>
     */
    public function transitionsTo(): array
    {
        return match ($this) {
            self::Active => [self::Revoked],
            self::Revoked => [],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Revoked => 'Revoked',
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Revoked;
    }
}
