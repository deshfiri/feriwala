<?php

namespace App\Domain\Referral\Enums;

use App\Support\StateMachine\TransitionableState;
use App\Support\Status\HasTranslatedLabel;

/**
 * Where one referral commission stands (D24, P7-43).
 *
 *   - **skipped** — the level was decided and pays nothing: the ancestor did not
 *     qualify, the level is switched off, or the base was spent. Kept so the
 *     chain shows every level, and why.
 *   - **pending** — calculated, not yet in the wallet: its holding period has
 *     not passed, or the beneficiary is not in a state that may be paid now.
 *   - **paid** — credited to the beneficiary's wallet.
 *   - **cancelled** — reversed before it was paid; no ledger entry either way.
 *   - **reversed** — paid, then taken back by a compensating ledger entry.
 *   - **reversal_owed** — paid, reversal decided, but the wallet could not
 *     cover it; retried until it can, never taken into a negative balance.
 */
enum CommissionStatus: string implements TransitionableState
{
    use HasTranslatedLabel;

    case Skipped = 'skipped';
    case Pending = 'pending';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
    case Reversed = 'reversed';
    case ReversalOwed = 'reversal_owed';

    /**
     * @return array<int, self>
     */
    public function transitionsTo(): array
    {
        return match ($this) {
            self::Pending => [self::Paid, self::Cancelled],
            self::Paid => [self::Reversed, self::ReversalOwed],
            self::ReversalOwed => [self::Reversed],
            self::Skipped, self::Cancelled, self::Reversed => [],
        };
    }

    protected static function statusLabelGroup(): string
    {
        return 'commission';
    }

    public function isTerminal(): bool
    {
        return $this->transitionsTo() === [];
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status) => $status->value, self::cases());
    }
}
