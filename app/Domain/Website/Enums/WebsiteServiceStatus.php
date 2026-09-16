<?php

namespace App\Domain\Website\Enums;

/**
 * Where a domain registration or a hosting term stands (§16.2, §41, P5-11).
 *
 * Both are provisioned by hand (D9), so `Pending` is a real and often long
 * state: the partner has asked and paid, and somebody at Feriwala has not
 * registered it yet. Treating that as active would tell a partner their domain
 * is live when nothing answers on it.
 */
enum WebsiteServiceStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function isRunning(): bool
    {
        return $this === self::Active;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting provisioning',
            self::Active => 'Active',
            self::Expired => 'Expired',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status) => $status->value, self::cases());
    }
}
