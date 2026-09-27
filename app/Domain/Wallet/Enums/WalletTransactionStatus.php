<?php

namespace App\Domain\Wallet\Enums;

use App\Support\StateMachine\TransitionableState;
use App\Support\Status\HasTranslatedLabel;

/**
 * Where one wallet transaction has got to (§23.3).
 *
 * All twelve statuses §23.3 names. The transitions between them are declared in
 * P2-7, where the `wallet_transactions` lifecycle lives; a ledger entry carries
 * whichever of these was true when it was posted, frozen, because the entry
 * itself never changes again.
 */
enum WalletTransactionStatus: string implements TransitionableState
{
    use HasTranslatedLabel;

    case Initiated = 'initiated';
    case Pending = 'pending';
    case OnHold = 'on_hold';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Available = 'available';
    case Settled = 'settled';
    case Paid = 'paid';
    case Rejected = 'rejected';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Reversed = 'reversed';

    /**
     * @return array<int, self>
     */
    public function transitionsTo(): array
    {
        return match ($this) {
            self::Initiated => [
                self::Pending, self::OnHold, self::UnderReview,
                self::Approved, self::Failed, self::Cancelled,
            ],

            /*
             * `Settled` is reachable directly from here and from `OnHold`
             * because that is what capturing a claim is: money that was set
             * aside against a charge, and then actually taken. Routing it
             * through `Approved` first would be inventing an approval nobody
             * gave.
             */
            self::Pending => [
                self::OnHold, self::UnderReview, self::Approved,
                self::Available, self::Settled, self::Failed, self::Cancelled,
            ],

            // A hold or a review is lifted one way or the other; neither is an
            // ending on its own.
            self::OnHold => [
                self::UnderReview, self::Approved, self::Available,
                self::Settled, self::Rejected, self::Cancelled,
            ],
            self::UnderReview => [self::Approved, self::Rejected, self::OnHold, self::Cancelled],

            self::Approved => [self::Available, self::Settled, self::Paid, self::Reversed],
            self::Available => [self::Settled, self::Paid, self::Reversed],
            self::Settled => [self::Paid, self::Reversed],

            // Money that has been paid out can be reversed, never un-paid.
            self::Paid => [self::Reversed],

            // Endings. A rejected or failed transaction is retried as a new
            // one, not revived — the same rule payments follow.
            self::Rejected => [],
            self::Failed => [],
            self::Cancelled => [],
            self::Reversed => [],
        };
    }

    /**
     * Whether this transaction has actually moved money into the wallet's
     * spendable balance.
     */
    public function isRealised(): bool
    {
        return in_array($this, [self::Available, self::Settled, self::Paid], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Rejected, self::Failed, self::Cancelled, self::Reversed,
        ], true);
    }

    protected static function statusLabelGroup(): string
    {
        return 'wallet_transaction';
    }

    /**
     * The tone a pill uses. The label always renders beside it — a financial
     * status must never depend on colour alone (§33.9).
     */
    public function tone(): string
    {
        return match ($this) {
            self::Available, self::Settled, self::Paid, self::Approved => 'success',
            self::Rejected, self::Failed => 'danger',
            self::Pending, self::OnHold, self::UnderReview, self::Reversed => 'warning',
            self::Initiated => 'info',
            self::Cancelled => 'neutral',
        };
    }
}
