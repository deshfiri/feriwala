<?php

namespace App\Domain\Withdrawal\Enums;

use App\Support\StateMachine\TransitionableState;
use App\Support\Status\HasTranslatedLabel;

/**
 * Where a Client/Partner `BusinessAccount` withdrawal request stands (§27,
 * D25 -- mirrors `SupplierWithdrawalStatus` exactly for a different owner).
 *
 * `Rejected`, `Failed` and `Reversed` are each terminal: an account that
 * wants to try again submits a new request rather than reopening one.
 */
enum AccountWithdrawalStatus: string implements TransitionableState
{
    use HasTranslatedLabel;

    /** Submitted by the account; wallet reservation made, waiting for staff. */
    case Requested = 'requested';

    /** A staff member has started looking at it. */
    case UnderReview = 'under_review';

    /** Cleared to be paid. */
    case Approved = 'approved';

    /** Declined; the reservation is released. */
    case Rejected = 'rejected';

    /** A payout is being sent. */
    case Processing = 'processing';

    /** Paid, with the external reference that proves it. */
    case Paid = 'paid';

    /** The payout attempt did not go through; the reservation is released. */
    case Failed = 'failed';

    /** A paid amount was later undone by the payout channel. */
    case Reversed = 'reversed';

    /**
     * @return array<int, self>
     */
    public function transitionsTo(): array
    {
        return match ($this) {
            self::Requested => [self::UnderReview, self::Rejected],
            self::UnderReview => [self::Approved, self::Rejected],
            self::Approved => [self::Processing],
            self::Processing => [self::Paid, self::Failed],
            self::Rejected, self::Failed, self::Paid, self::Reversed => [],
        };
    }

    public function isTerminal(): bool
    {
        return $this->transitionsTo() === [];
    }

    /**
     * Whether the wallet reservation is still held against this request.
     */
    public function holdsReservation(): bool
    {
        return in_array($this, [self::Requested, self::UnderReview, self::Approved, self::Processing], true);
    }

    protected static function statusLabelGroup(): string
    {
        return 'withdrawal';
    }

    public function tone(): string
    {
        return match ($this) {
            self::Requested, self::UnderReview => 'info',
            self::Approved, self::Processing => 'warning',
            self::Paid => 'success',
            self::Rejected, self::Failed, self::Reversed => 'neutral',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status) => $status->value, self::cases());
    }
}
