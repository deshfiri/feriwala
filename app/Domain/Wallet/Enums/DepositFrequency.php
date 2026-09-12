<?php

namespace App\Domain\Wallet\Enums;

/**
 * How often a deposit obligation comes round (§24.1).
 *
 * §24.1 asks for both "deposit frequency" and "one-time or recurring deposit",
 * which are one decision with two names: a deposit is either asked for once, at
 * activation, or asked for again on an interval. The interval itself is a
 * separate column, because "recurring" without a period is not an instruction.
 */
enum DepositFrequency: string
{
    case OneTime = 'one_time';
    case Recurring = 'recurring';

    public function repeats(): bool
    {
        return $this === self::Recurring;
    }

    public function label(): string
    {
        return match ($this) {
            self::OneTime => 'One-time',
            self::Recurring => 'Recurring',
        };
    }
}
