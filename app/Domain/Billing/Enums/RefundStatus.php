<?php

namespace App\Domain\Billing\Enums;

use App\Support\StateMachine\TransitionableState;

/**
 * Where a refund request has got to (D17).
 *
 * `Approved` and `Processed` are separate states, deliberately. Approval is a
 * decision; processing is money leaving. A gateway refund can be approved on
 * Monday and fail on Tuesday, and a single "refunded" state would have said the
 * money went back when it did not.
 *
 * Rejected is terminal for that request, not for the payment: a refused refund
 * may be asked for again with new grounds, which is why the open-request index
 * only covers `Requested`.
 */
enum RefundStatus: string implements TransitionableState
{
    /** Waiting on an administrator (D17 makes every refund a decision). */
    case Requested = 'requested';

    /** Granted, but the money has not moved yet. */
    case Approved = 'approved';

    /** Refused, with a recorded reason. */
    case Rejected = 'rejected';

    /** The money has actually gone back. */
    case Processed = 'processed';

    /** Approved, then failed at the gateway. */
    case Failed = 'failed';

    /**
     * @return array<int, self>
     */
    public function transitionsTo(): array
    {
        return match ($this) {
            self::Requested => [self::Approved, self::Rejected],

            // A failed refund stays approved in principle — the decision was
            // made and stands — so it can be retried without being decided
            // again.
            self::Approved => [self::Processed, self::Failed],
            self::Failed => [self::Processed],

            self::Rejected, self::Processed => [],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Awaiting decision',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Processed => 'Refunded',
            self::Failed => 'Refund failed',
        };
    }

    public function isDecided(): bool
    {
        return $this !== self::Requested;
    }

    public function isTerminal(): bool
    {
        return $this === self::Rejected || $this === self::Processed;
    }
}
