<?php

namespace App\Domain\Website\Enums;

/**
 * Where one website charge stands (§16.2, §24, P5-10).
 *
 * A charge is raised **due** and is only ever paid by a wallet debit that
 * carries its own idempotency key, so a charge cannot be paid twice however
 * many times the button is pressed. Waived and cancelled are kept apart:
 * waiving means Feriwala decided not to take the money, cancelling means the
 * thing being charged for never happened.
 */
enum WebsiteChargeStatus: string
{
    case Due = 'due';
    case Paid = 'paid';
    case Waived = 'waived';
    case Cancelled = 'cancelled';

    public function isOutstanding(): bool
    {
        return $this === self::Due;
    }

    public function isClosed(): bool
    {
        return $this !== self::Due;
    }

    public function label(): string
    {
        return match ($this) {
            self::Due => 'Due',
            self::Paid => 'Paid',
            self::Waived => 'Waived',
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
