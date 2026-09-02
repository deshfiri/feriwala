<?php

namespace App\Domain\Billing\Enums;

use App\Support\StateMachine\TransitionableState;

/**
 * The lifecycle of a payment (§26.4).
 */
enum PaymentStatus: string implements TransitionableState
{
    /** Quoted but not yet sent to a gateway. */
    case Draft = 'draft';

    case Initiated = 'initiated';
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';

    /**
     * @return array<int, self>
     */
    public function transitionsTo(): array
    {
        return match ($this) {
            self::Draft => [self::Initiated, self::Cancelled],

            self::Initiated => [self::Pending, self::Paid, self::Failed, self::Cancelled],

            // A gateway can report success or failure long after the redirect.
            self::Pending => [self::Paid, self::Failed, self::Cancelled],

            // Money that arrived can be sent back, but never un-arrive.
            self::Paid => [self::Refunded, self::PartiallyRefunded],

            // A failed payment is retried as a new attempt, not by reviving
            // this one — the gateway reference belongs to the failed attempt.
            self::Failed => [],
            self::Cancelled => [],

            self::PartiallyRefunded => [self::Refunded],
            self::Refunded => [],
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Failed, self::Cancelled, self::Refunded], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Initiated => 'Initiated',
            self::Pending => 'Pending',
            self::Paid => 'Paid',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
            self::Refunded => 'Refunded',
            self::PartiallyRefunded => 'Partially refunded',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Paid => 'success',
            self::Failed, self::Cancelled => 'danger',
            self::Pending, self::PartiallyRefunded, self::Refunded => 'warning',
            self::Initiated => 'info',
            self::Draft => 'neutral',
        };
    }

    /**
     * Whether the money has actually arrived.
     *
     * The only status that should release anything — activate an account, credit
     * a wallet, reserve stock permanently. "Pending" looks like success on a
     * gateway's redirect page and is not.
     */
    public function isSettled(): bool
    {
        return in_array($this, [self::Paid, self::PartiallyRefunded], true);
    }
}
