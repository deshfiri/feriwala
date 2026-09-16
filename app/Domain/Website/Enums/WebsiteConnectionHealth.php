<?php

namespace App\Domain\Website\Enums;

/**
 * How the integration with a storefront is behaving (§16.2, §17.3, P5-28).
 *
 * Derived from what actually happened — deliveries that failed, calls that were
 * refused — rather than from a heartbeat nobody reads. `Unknown` is the honest
 * answer before a website has ever connected, and is not the same as healthy.
 */
enum WebsiteConnectionHealth: string
{
    case Unknown = 'unknown';
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Failing = 'failing';

    public function label(): string
    {
        return match ($this) {
            self::Unknown => 'Not connected yet',
            self::Healthy => 'Healthy',
            self::Degraded => 'Degraded',
            self::Failing => 'Failing',
        };
    }

    /**
     * Whether this state is worth telling the partner about.
     */
    public function needsAttention(): bool
    {
        return $this === self::Degraded || $this === self::Failing;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $health) => $health->value, self::cases());
    }
}
