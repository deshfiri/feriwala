<?php

namespace App\Domain\Kyc\Enums;

use App\Support\Status\HasTranslatedLabel;

/**
 * What happens to a trading business while a re-verification round is
 * outstanding, and after its deadline passes (§7.4).
 *
 * §7.4 offers consequences rather than requiring them — "may remain blocked",
 * "may be restricted" — so each one is chosen per case by the member of staff
 * who opens the round. They are independent on purpose: "stop them taking new
 * wholesale orders but let them keep withdrawing what they have already
 * earned" is a real position, and a single on/off restriction cannot express
 * it.
 *
 * **Every one of these blocks only new activity.** Nothing here cancels an
 * order, releases a reservation, reverses a ledger entry, unpublishes a
 * website or touches an invoice. A business asked to re-verify has not been
 * found guilty of anything, and its existing obligations still have to be
 * settled — which is also why none of these ever block payment settlement,
 * refunds, reconciliation or financial reversals.
 */
enum KycConsequence: string
{
    use HasTranslatedLabel;

    /**
     * Tell them, and nothing else.
     *
     * The default, and the only one that is safe to assume: a business that
     * has done nothing wrong yet keeps trading while it answers.
     */
    case WarningOnly = 'warning_only';

    /**
     * No new orders, wholesale or website/dropshipping. Existing ones are
     * untouched.
     *
     * One consequence rather than two: a business we are not sure about
     * should not be able to take new work through whichever channel was left
     * switched on.
     */
    case BlockNewOrders = 'block_new_orders';

    /** No new products published to a partner website. Published ones stay up. */
    case BlockPublishing = 'block_publishing';

    /** No new withdrawal requests. Requests already in flight are untouched. */
    case BlockWithdrawals = 'block_withdrawals';

    /**
     * Suspend the account once the deadline passes.
     *
     * The heaviest, and the only one that changes the account's status. It
     * applies **after** the deadline, never while the business still has time
     * to answer.
     */
    case SuspendAfterDeadline = 'suspend_after_deadline';

    protected static function statusLabelGroup(): string
    {
        return 'kyc_consequence';
    }

    /**
     * Whether this consequence bites while the round is merely outstanding, as
     * opposed to only once the deadline has passed.
     */
    public function appliesBeforeDeadline(): bool
    {
        return match ($this) {
            self::BlockNewOrders,
            self::BlockPublishing,
            self::BlockWithdrawals => true,
            self::WarningOnly,
            self::SuspendAfterDeadline => false,
        };
    }

    /**
     * The consequences a case carries when staff chose nothing in particular.
     *
     * Warning only. The safe failure is that a business nobody has decided to
     * restrict keeps trading.
     *
     * @return array<int, self>
     */
    public static function default(): array
    {
        return [self::WarningOnly];
    }
}
