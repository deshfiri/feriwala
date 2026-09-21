<?php

namespace App\Domain\Order\Enums;

/**
 * What became of the money for a return (§26.3, §28, D17, P6-12).
 *
 * Separate from the return's own status, because they answer different
 * questions: the return says where the goods are, this says where the money is.
 * A return can be received and inspected while its refund is still in flight at
 * a gateway, and collapsing the two would have the goods waiting on the money.
 *
 * **ManualReview is not a failure.** It is the honest state for a refund nobody
 * can send automatically — a cash-on-delivery order, where the money never came
 * through a gateway and so cannot go back through one. Guessing a destination
 * (a wallet credit, a payout) would be inventing where somebody's money goes.
 */
enum ReturnRefundState: string
{
    /** Nothing to give back — goods exchanged, or a refused return. */
    case NotRequired = 'not_required';

    /** Asked for and on its way: decided, or in flight at the gateway. */
    case Pending = 'pending';

    /** The money has gone back. */
    case Completed = 'completed';

    /** The gateway refused or failed. It can be tried again. */
    case Failed = 'failed';

    /** A person has to settle this one, and has been told so. */
    case ManualReview = 'manual_review';

    public function label(): string
    {
        return match ($this) {
            self::NotRequired => 'No refund',
            self::Pending => 'Refund pending',
            self::Completed => 'Refunded',
            self::Failed => 'Refund failed',
            self::ManualReview => 'Needs manual settlement',
        };
    }

    /**
     * Whether somebody still has to do something about the money.
     */
    public function needsAttention(): bool
    {
        return $this === self::Failed || $this === self::ManualReview;
    }

    public function tone(): string
    {
        return match ($this) {
            self::Completed => 'success',
            self::Failed => 'danger',
            self::ManualReview, self::Pending => 'warning',
            self::NotRequired => 'neutral',
        };
    }
}
