<?php

namespace App\Domain\Supplier\Enums;

use App\Support\StateMachine\TransitionableState;

/**
 * Where a Supplier payable stands (D25, P13-22).
 *
 * A payable is a claim, so nothing here is money: `Settled` only records that a
 * ledger posting (P13-23) paid it. Two states are terminal — a payable that was
 * `Cancelled` before it was ever earned, and one fully `Reversed` by returns —
 * and a `Settled` one can still be reversed afterwards, which is the boundary
 * P13-23 claws back through the wallet.
 */
enum PayableStatus: string implements TransitionableState
{
    /** Accrued when the order was placed; earned only on delivery *and* a settled payment. */
    case Pending = 'pending';

    /** Delivered and paid for: may now be settled into the Supplier's wallet (P13-23). */
    case Eligible = 'eligible';

    /** Paid out, by the ledger posting named on the payable. */
    case Settled = 'settled';

    /** Some of its quantity came back; the rest stands. */
    case PartiallyReversed = 'partially_reversed';

    /** All of its quantity came back. */
    case Reversed = 'reversed';

    /** The order never went ahead: nobody was paid and nothing was earned. */
    case Cancelled = 'cancelled';

    /** Frozen with the order until a person resolves it. */
    case OnHold = 'on_hold';

    /**
     * @return array<int, self>
     */
    public function transitionsTo(): array
    {
        return match ($this) {
            self::Pending => [self::Eligible, self::Cancelled, self::OnHold, self::PartiallyReversed, self::Reversed],
            self::OnHold => [self::Pending, self::Cancelled],
            self::Eligible => [self::Settled, self::OnHold, self::PartiallyReversed, self::Reversed],
            self::PartiallyReversed => [self::Settled, self::Reversed],
            self::Settled => [self::PartiallyReversed, self::Reversed],
            self::Reversed, self::Cancelled => [],
        };
    }

    public function isTerminal(): bool
    {
        return $this->transitionsTo() === [];
    }

    /**
     * Whether this payable still stands as something owed, in whole or in part.
     */
    public function isOwed(): bool
    {
        return in_array($this, [self::Pending, self::Eligible, self::PartiallyReversed, self::OnHold, self::Settled], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Eligible => 'Eligible',
            self::Settled => 'Settled',
            self::PartiallyReversed => 'Partially reversed',
            self::Reversed => 'Reversed',
            self::Cancelled => 'Cancelled',
            self::OnHold => 'On hold',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'info',
            self::Eligible, self::Settled => 'success',
            self::PartiallyReversed, self::OnHold => 'warning',
            self::Reversed, self::Cancelled => 'neutral',
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
