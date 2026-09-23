<?php

namespace App\Domain\Supplier\Enums;

use App\Support\StateMachine\TransitionableState;

/**
 * Where a Supplier withdrawal request stands (D25, P13-24).
 *
 * `Rejected`, `Failed` and `Reversed` are each terminal: a Supplier who wants
 * to try again submits a new request rather than reopening one. `Reversed`
 * exists for a payout that the bank, bKash or Nagad undoes after it was
 * already marked `Paid` — the schema and this state exist for it, but no staff
 * action reaches it yet in this batch (P13-24 covers request through pay/fail;
 * an external-reversal action is a small, separate addition once a payout
 * channel actually reports one).
 */
enum SupplierWithdrawalStatus: string implements TransitionableState
{
    /** Submitted by the Supplier; reservation made, waiting for staff. */
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
            // Approval is final regarding whether the request was legitimate;
            // the only way out from here is proceeding to payment. Anything
            // that goes wrong afterwards is Failed, not retroactively Rejected.
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
     * Whether the reserved amount is still held against this request.
     */
    public function holdsReservation(): bool
    {
        return in_array($this, [self::Requested, self::UnderReview, self::Approved, self::Processing], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Requested',
            self::UnderReview => 'Under review',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Processing => 'Processing',
            self::Paid => 'Paid',
            self::Failed => 'Failed',
            self::Reversed => 'Reversed',
        };
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
