<?php

namespace App\Domain\Order\Enums;

use App\Support\StateMachine\TransitionableState;
use App\Support\Status\HasTranslatedLabel;

/**
 * Where a Partner's profit on one Storefront order stands.
 *
 *   - **awaiting_facts** — not yet Delivered, or the money not yet collected.
 *   - **holding** — both facts in; credited when the 24-hour hold ends.
 *   - **blocked** — a cancellation request, return, damage case or refund is
 *     open; held until it is resolved, then back to holding.
 *   - **review_required** — below zero, a cost that cannot be proven, or a
 *     collection that does not match the order: a person decides.
 *   - **released** — credited to the Partner's withdrawable balance. A later
 *     return takes the difference back by a compensating debit.
 *   - **reversed** — released, then taken back in full.
 *   - **no_profit** — worked out to exactly nothing: no Wallet entry.
 *   - **reported_only** — paid on the Partner's own gateway: shown, never credited.
 *   - **failed** — a technical failure while crediting; staff may retry.
 *   - **cancelled** — the order was cancelled before anything was credited.
 */
enum StorefrontProfitStatus: string implements TransitionableState
{
    use HasTranslatedLabel;

    case AwaitingFacts = 'awaiting_facts';
    case Holding = 'holding';
    case Blocked = 'blocked';
    case ReviewRequired = 'review_required';
    case Released = 'released';
    case Reversed = 'reversed';
    case NoProfit = 'no_profit';
    case ReportedOnly = 'reported_only';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * @return array<int, self>
     */
    public function transitionsTo(): array
    {
        $open = [self::AwaitingFacts, self::Holding, self::Blocked, self::ReviewRequired, self::NoProfit, self::ReportedOnly, self::Cancelled];

        return match ($this) {
            self::AwaitingFacts, self::Holding, self::Blocked, self::ReviewRequired, self::NoProfit, self::ReportedOnly => [
                ...array_values(array_filter($open, fn (self $status) => $status !== $this)),
                self::Released,
                self::Failed,
            ],
            self::Failed => [self::Holding, self::Blocked, self::ReviewRequired, self::Released, self::NoProfit, self::Cancelled],
            self::Released => [self::Reversed],
            self::Reversed, self::Cancelled => [],
        };
    }

    protected static function statusLabelGroup(): string
    {
        return 'storefront_profit';
    }

    public function isTerminal(): bool
    {
        return $this->transitionsTo() === [];
    }

    /** Whether its figures may still change before anything is credited. */
    public function isUnsettled(): bool
    {
        return ! in_array($this, [self::Released, self::Reversed, self::Cancelled], true);
    }

    public function tone(): string
    {
        return match ($this) {
            self::Released => 'success',
            self::Holding, self::AwaitingFacts => 'info',
            self::Blocked, self::ReviewRequired => 'warning',
            self::Failed => 'danger',
            self::Reversed, self::Cancelled, self::NoProfit, self::ReportedOnly => 'neutral',
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
