<?php

namespace App\Domain\Wallet\Enums;

/**
 * Which kind of money a wallet entry moved (D-new: typed wallet balances).
 *
 * A wallet is one balance with two kinds of money in it, and the kind is
 * written on every ledger entry so it can be re-derived from the ledger alone:
 *
 *   - **Platform credit** can be spent inside Banij — orders, fees, packages,
 *     subscriptions — and can never be withdrawn.
 *   - **Withdrawable** can be withdrawn, and can also be spent inside the
 *     platform.
 *
 * Only the platform-credit part is stored on the wallet; withdrawable is the
 * rest of `total`, so every balance that existed before this change is
 * withdrawable exactly as it was, with nothing migrated.
 */
enum BalanceType: string
{
    case PlatformCredit = 'platform_credit';
    case Withdrawable = 'withdrawable';

    public function isPlatformCredit(): bool
    {
        return $this === self::PlatformCredit;
    }

    public function other(): self
    {
        return $this === self::PlatformCredit ? self::Withdrawable : self::PlatformCredit;
    }

    public function label(): string
    {
        return match ($this) {
            self::PlatformCredit => 'Platform credit',
            self::Withdrawable => 'Withdrawable',
        };
    }
}
