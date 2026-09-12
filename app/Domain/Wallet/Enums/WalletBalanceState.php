<?php

namespace App\Domain\Wallet\Enums;

/**
 * How a wallet stands against what it is required to hold (§24.1, §24.3).
 *
 * Three states, and the difference between the last two is what §24.3 grades its
 * actions on:
 *
 *   - **Healthy** — at or above everything asked of it.
 *   - **Low** — below the low threshold but still meeting the obligation. A
 *     warning, and nothing more: nothing is restricted for being close to the
 *     line.
 *   - **Critical** — below what it is required to hold. This is the state the
 *     graded actions of §24.3 act on, and the one a grace period counts down.
 *
 * "Low" deliberately does not mean "nearly out of money". It means the balance
 * has passed a figure an administrator chose as worth mentioning, which may be
 * well above the requirement.
 */
enum WalletBalanceState: string
{
    case Healthy = 'healthy';
    case Low = 'low';
    case Critical = 'critical';

    /**
     * Whether the account is short of its obligation.
     */
    public function isShort(): bool
    {
        return $this === self::Critical;
    }

    /**
     * Whether this state is worth telling the account holder about (§24.3).
     */
    public function warrantsNotice(): bool
    {
        return $this !== self::Healthy;
    }

    public function label(): string
    {
        return match ($this) {
            self::Healthy => 'Healthy',
            self::Low => 'Low balance',
            self::Critical => 'Below required balance',
        };
    }

    /**
     * Never the only carrier of the meaning — the label always goes with it
     * (§33.9).
     */
    public function tone(): string
    {
        return match ($this) {
            self::Healthy => 'success',
            self::Low => 'warning',
            self::Critical => 'danger',
        };
    }
}
