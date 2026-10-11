<?php

namespace App\Domain\ExternalStore\Enums;

use App\Support\StateMachine\TransitionableState;

/**
 * Where a connection to a partner's external store stands (D29).
 *
 *   - `Pending` — saved and verified once, its first import not yet through.
 *   - `Connected` — the last sync succeeded.
 *   - `Failing` — syncs have been failing in a row; it keeps being retried.
 *   - `Disconnected` — the partner ended it. Its credentials are forgotten and
 *     its orders are kept; reconnecting starts it again from `Pending`.
 */
enum ExternalStoreStatus: string implements TransitionableState
{
    case Pending = 'pending';
    case Connected = 'connected';
    case Failing = 'failing';
    case Disconnected = 'disconnected';

    /**
     * @return array<int, self>
     */
    public function transitionsTo(): array
    {
        return match ($this) {
            self::Pending => [self::Connected, self::Failing, self::Disconnected],
            self::Connected => [self::Failing, self::Disconnected],
            self::Failing => [self::Connected, self::Disconnected],
            self::Disconnected => [self::Pending],
        };
    }

    public function label(): string
    {
        return __('stores.statuses.'.$this->value);
    }

    /**
     * Never terminal: a disconnected store may be connected again.
     */
    public function isTerminal(): bool
    {
        return false;
    }

    public function isLive(): bool
    {
        return $this !== self::Disconnected;
    }

    /**
     * The `StatusPill` tone. The label always travels with it (§33.9).
     */
    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'info',
            self::Connected => 'success',
            self::Failing => 'danger',
            self::Disconnected => 'neutral',
        };
    }
}
