<?php

namespace App\Domain\Wallet;

use App\Domain\Account\Enums\AccountStatus;

/**
 * The legal way from one account status to another (§5, §24.3).
 *
 * §24.3 grades its actions and the status machine already agrees with it:
 * `Active → LowWalletBalance → WalletTopupRequired → TemporarilyRestricted →
 * TemporarilyDisabled` is exactly the escalation it describes, and the machine
 * refuses to skip a rung. So a stage that wants an account disabled cannot
 * simply set it disabled — it has to walk.
 *
 * Rather than hard-coding that ladder, the route is searched over the machine's
 * own declarations. Two things follow: a transition this finds is legal by
 * construction, and an edge added or removed in `transitionsTo()` changes the
 * route without anybody remembering to update a second copy of it.
 *
 * Two statuses are never routed **through**, only to: `Suspended` and `Closed`.
 * Both are decisions somebody made about the relationship, not rungs on a
 * ladder, and passing through one on the way somewhere else would suspend an
 * account as a side effect of a balance check.
 */
class AccountStatusRoute
{
    /**
     * Statuses that may end a route but never form part of one.
     *
     * @var array<int, AccountStatus>
     */
    protected const NEVER_PASSED_THROUGH = [
        AccountStatus::Suspended,
        AccountStatus::Closed,
    ];

    /**
     * The shortest legal sequence of statuses from `$from` to `$to`.
     *
     * Excludes the starting status and includes the destination. An empty array
     * means either that there is nothing to do or that no legal route exists —
     * the caller cannot tell them apart and does not need to: in both cases it
     * makes no move.
     *
     * @return array<int, AccountStatus>
     */
    public function between(AccountStatus $from, AccountStatus $to): array
    {
        if ($from === $to) {
            return [];
        }

        /** @var array<string, array<int, AccountStatus>> $routes */
        $routes = [$from->value => []];
        $queue = [$from];

        while ($queue !== []) {
            $current = array_shift($queue);

            foreach ($current->transitionsTo() as $next) {
                if (isset($routes[$next->value])) {
                    continue;
                }

                $routes[$next->value] = [...$routes[$current->value], $next];

                if ($next === $to) {
                    return $routes[$next->value];
                }

                if (in_array($next, self::NEVER_PASSED_THROUGH, true)) {
                    // Reachable as a destination, never as a corridor.
                    continue;
                }

                $queue[] = $next;
            }
        }

        return [];
    }

    /**
     * Whether there is any legal way from one to the other.
     */
    public function exists(AccountStatus $from, AccountStatus $to): bool
    {
        return $from === $to || $this->between($from, $to) !== [];
    }
}
