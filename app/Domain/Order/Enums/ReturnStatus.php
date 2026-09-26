<?php

namespace App\Domain\Order\Enums;

use App\Support\StateMachine\TransitionableState;
use App\Support\Status\HasTranslatedLabel;

/**
 * Where a return has got to (§18.2, §26.3, contract §6.3, P6-12).
 *
 * The smallest set that keeps the four decisions apart, because each is taken
 * by different people at different times and each can stop there:
 *
 *   - **Requested** — asked for. Nothing has moved.
 *   - **Approved** — Feriwala agreed to take the goods back, for a stated
 *     quantity per line. Still nothing has moved.
 *   - **Received** — the goods arrived and were inspected. Stock is restored
 *     here, each line to the disposition it was given.
 *   - **Refunded** — money went back, or was explicitly marked for a person to
 *     settle by hand.
 *
 * `Rejected` and `Cancelled` are the two ways it ends early: Feriwala refusing,
 * with a reason, and the customer or partner withdrawing. Neither is a way back
 * into the flow — a refused return is asked for again as a new one, so the
 * refusal stays on the record as what was decided at the time.
 */
enum ReturnStatus: string implements TransitionableState
{
    use HasTranslatedLabel;

    case Requested = 'requested';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Received = 'received';
    case Refunded = 'refunded';
    case Cancelled = 'cancelled';

    /**
     * @return array<int, self>
     */
    public function transitionsTo(): array
    {
        return match ($this) {
            self::Requested => [self::Approved, self::Rejected, self::Cancelled],

            // Approved and nothing arrived: it may still be called off. Once
            // goods are in, it is a matter for the refund, not a cancellation.
            self::Approved => [self::Received, self::Cancelled],
            self::Received => [self::Refunded],

            self::Rejected, self::Refunded, self::Cancelled => [],
        };
    }

    protected static function statusLabelGroup(): string
    {
        return 'return';
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Rejected, self::Refunded, self::Cancelled], true);
    }

    /**
     * Whether goods may still be expected against this return.
     */
    public function isOpen(): bool
    {
        return in_array($this, [self::Requested, self::Approved], true);
    }

    public function tone(): string
    {
        return match ($this) {
            self::Approved, self::Received => 'info',
            self::Refunded => 'success',
            self::Rejected, self::Cancelled => 'danger',
            self::Requested => 'warning',
        };
    }
}
