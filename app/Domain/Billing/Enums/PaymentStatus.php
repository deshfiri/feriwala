<?php

namespace App\Domain\Billing\Enums;

use App\Domain\Billing\Models\Payment;
use App\Support\StateMachine\TransitionableState;
use App\Support\Status\HasTranslatedLabel;

/**
 * The lifecycle of a payment (§26.4).
 */
enum PaymentStatus: string implements TransitionableState
{
    use HasTranslatedLabel;

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

    /**
     * The statuses a person may mark paid by hand (`payment.settle_manually`).
     *
     * Deliberately **not** part of {@see transitionsTo()}: no callback, sweep or
     * stray request may revive a payment that ended. Only the audited manual
     * settlement reads this list, through
     * {@see Payment::transitionToPaidManually()}.
     * `Draft` is left out because it was never sent to a gateway, so there is no
     * money to account for.
     *
     * @return array<int, self>
     */
    public static function manuallySettleable(): array
    {
        return [
            self::Initiated,
            self::Pending,
            self::Failed,
            self::Cancelled,
            self::ReconciliationRequired,
        ];
    }

    /**
     * The statuses a payment somebody is still in the middle of can hold.
     *
     * Everything that has not reached an answer yet. Used to find the attempt a
     * person is returning from, which is a question the session can answer
     * honestly — unlike anything in the gateway's redirect.
     *
     * @return array<int, self>
     */
    public static function open(): array
    {
        return [self::Draft, self::Initiated, self::Pending];
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

    protected static function statusLabelGroup(): string
    {
        return 'payment';
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
