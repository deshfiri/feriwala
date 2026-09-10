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
     * Money arrived for a purchase that had already been closed (§26.4).
     *
     * A checkout expires, and the gateway confirms the payment afterwards. Both
     * facts are true and neither may be discarded: the money is real, and the
     * purchase is not being revived on the strength of a late callback. This is
     * the state that says so out loud, and it waits for a person.
     */
    case ReconciliationRequired = 'reconciliation_required';

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

            /*
             * A failed or cancelled payment is retried as a new attempt, never
             * by reviving this one — the gateway reference belongs to the
             * attempt that ended.
             *
             * The one move each has left is to `ReconciliationRequired`, and it
             * is not a revival: a gateway can confirm a payment after we have
             * given up on it, and the money is real whether or not the purchase
             * is. Nothing downstream reads this as settled, so nothing is
             * activated by it — it exists so the money cannot vanish quietly.
             */
            self::Failed => [self::ReconciliationRequired],
            self::Cancelled => [self::ReconciliationRequired],

            // Waits for a person. A refund is the way out, and the refund module
            // is not built yet.
            self::ReconciliationRequired => [],

            self::PartiallyRefunded => [self::Refunded],
            self::Refunded => [],
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Failed,
            self::Cancelled,
            self::Refunded,
            self::ReconciliationRequired,
        ], true);
    }

    /**
     * Whether this payment is waiting for somebody to sort it out.
     *
     * Read by the administration screens so these cannot hide among ordinary
     * failures — a failed payment needs nothing from anyone; this one holds
     * money that arrived against a purchase nobody received.
     */
    public function needsReconciliation(): bool
    {
        return $this === self::ReconciliationRequired;
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
            self::ReconciliationRequired => 'Needs reconciliation',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Paid => 'success',
            self::Failed, self::Cancelled => 'danger',
            self::Pending, self::PartiallyRefunded, self::Refunded => 'warning',
            self::ReconciliationRequired => 'warning',
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
