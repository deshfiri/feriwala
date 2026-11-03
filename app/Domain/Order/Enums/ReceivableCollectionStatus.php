<?php

namespace App\Domain\Order\Enums;

use App\Support\StateMachine\TransitionableState;
use App\Support\Status\HasTranslatedLabel;

/**
 * Whether Banij's frozen receivable on a Partner-gateway order has been taken
 * from the Partner's Wallet (D36).
 *
 * Not the payment's status. The customer's payment is `paid` the moment the
 * Partner's gateway confirms it, and stays so; this says whether what Banij is
 * owed has been collected, is still waiting for the Wallet, cannot be collected
 * because the stock is gone, or ran out of time.
 *
 * The database holds the same map in `feriwala_receivable_collection_move_is_allowed()`.
 */
enum ReceivableCollectionStatus: string implements TransitionableState
{
    use HasTranslatedLabel;

    /** Payment verified; the Wallet has not been asked yet. */
    case PendingCollection = 'pending_collection';

    /** The Wallet could not cover the whole amount; the stock is held until the deadline. */
    case WalletInsufficient = 'wallet_insufficient';

    /** The stock held for the order lapsed and none could be set aside again; a person decides. */
    case ReservationRecoveryRequired = 'reservation_recovery_required';

    /** The whole amount was debited from the Wallet, once. */
    case Collected = 'collected';

    /** The deadline passed uncollected; the customer's payment needs refund handling by a person. */
    case DeadlineExpiredRefundRequired = 'deadline_expired_refund_required';

    /** Reserved for the approved reversal flow; nothing writes it yet. */
    case PartiallyReversed = 'partially_reversed';

    /** Reserved for the approved reversal flow; nothing writes it yet. */
    case Reversed = 'reversed';

    /**
     * @return array<int, self>
     */
    public function transitionsTo(): array
    {
        return match ($this) {
            self::PendingCollection => [
                self::Collected, self::WalletInsufficient, self::ReservationRecoveryRequired, self::DeadlineExpiredRefundRequired,
            ],
            self::WalletInsufficient => [
                self::Collected, self::ReservationRecoveryRequired, self::DeadlineExpiredRefundRequired,
            ],
            self::ReservationRecoveryRequired => [
                self::Collected, self::WalletInsufficient, self::DeadlineExpiredRefundRequired,
            ],
            self::Collected => [self::PartiallyReversed, self::Reversed],
            self::PartiallyReversed => [self::Reversed],
            self::DeadlineExpiredRefundRequired, self::Reversed => [],
        };
    }

    public function isTerminal(): bool
    {
        return $this->transitionsTo() === [];
    }

    /**
     * Still waiting for the Wallet, and still entitled to be collected.
     *
     * The statuses a top-up releases and a deadline closes. Only these hold
     * stock, and only these may block a new withdrawal — and then only until the
     * deadline.
     */
    public function isActive(): bool
    {
        return $this === self::PendingCollection || $this === self::WalletInsufficient;
    }

    public function isCollected(): bool
    {
        return in_array($this, [self::Collected, self::PartiallyReversed, self::Reversed], true);
    }

    /**
     * The one word the screens and APIs use for where the receivable stands.
     */
    public function stage(): string
    {
        return match ($this) {
            self::PendingCollection => 'awaiting_wallet_collection',
            self::WalletInsufficient => 'wallet_insufficient',
            self::ReservationRecoveryRequired => 'stock_recovery_required',
            self::Collected, self::PartiallyReversed, self::Reversed => 'collected',
            self::DeadlineExpiredRefundRequired => 'expired_refund_required',
        };
    }

    protected static function statusLabelGroup(): string
    {
        return 'receivable_collection';
    }

    public function tone(): string
    {
        return match ($this) {
            self::PendingCollection => 'info',
            self::WalletInsufficient, self::ReservationRecoveryRequired => 'warning',
            self::Collected => 'success',
            self::DeadlineExpiredRefundRequired => 'danger',
            self::PartiallyReversed, self::Reversed => 'neutral',
        };
    }
}
