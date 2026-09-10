<?php

namespace App\Domain\Wallet\Policies;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Wallet\Models\Wallet;
use App\Models\User;

/**
 * Who may look at a wallet, and who may move money in one (§23, §31.3, §32).
 *
 * Two different questions, and they are answered from different places:
 *
 *   - **A member reads their own.** Not through a permission at all, but through
 *     the membership: the account owns the wallet, so the only wallet a business
 *     user can reach is the one hanging off their own account. There is no
 *     identifier in their URL to change (§31.3).
 *   - **An administrator reads anybody's.** Through `wallet.view`, which is a
 *     platform permission and has nothing to do with owning a business.
 *
 * Moving money by hand is narrower again, and split in two on purpose.
 * `wallet.adjust_wallet` is deciding a balance should change; `wallet.
 * reverse_transaction` is undoing a posting that already happened. Somebody
 * trusted to put right yesterday's mistake is not automatically somebody trusted
 * to invent a credit.
 *
 * Not a model policy: the administration screens list wallets before there is a
 * row to ask about, and Laravel's model-bound abilities have nothing to bind to
 * at that point.
 */
class WalletPolicy
{
    /**
     * Whether this person may open the administration wallet screens.
     */
    public static function canViewAny(User $user): bool
    {
        return $user->can(self::wallet(PermissionAction::View));
    }

    /**
     * Whether this person may read this particular wallet.
     *
     * A member of the owning account may, because it is theirs. Anybody else
     * needs the platform permission — and a business user reaching for another
     * account's wallet holds neither, so they are refused rather than shown a
     * wallet with somebody else's money in it.
     */
    public static function canView(User $user, Wallet $wallet): bool
    {
        return self::belongsTo($user, $wallet) || self::canViewAny($user);
    }

    /**
     * Whether this person may take the statement away as a file (§33.7).
     *
     * A member exporting their own statement needs no platform permission: it is
     * the same money the screen already shows them, in a form a spreadsheet can
     * read. An administrator exporting somebody else's needs `wallet.export`,
     * because that is a copy of another business's finances leaving the system.
     */
    public static function canExport(User $user, Wallet $wallet): bool
    {
        return self::belongsTo($user, $wallet)
            || $user->can(self::wallet(PermissionAction::Export));
    }

    /**
     * Whether this person may move money by hand (§23.1's manual adjustment).
     */
    public static function canAdjust(User $user): bool
    {
        return $user->can(self::wallet(PermissionAction::AdjustWallet));
    }

    /**
     * Whether this person may reverse a posted entry (§23.2).
     */
    public static function canReverse(User $user): bool
    {
        return $user->can(self::wallet(PermissionAction::ReverseTransaction));
    }

    /**
     * Whether this person may read the staff-only notes on an entry.
     *
     * The note is why an administrator did something, written for other
     * administrators. §7.3 keeps that class of remark away from the person it is
     * about, and this is the ledger's version of the same rule — so the account
     * holder never sees it, and neither does an administrator without
     * `ledger.view_sensitive_data`.
     */
    public static function canViewSensitive(User $user): bool
    {
        return $user->can(PermissionCatalogue::name(
            PermissionModule::Ledger,
            PermissionAction::ViewSensitiveData,
        ));
    }

    /**
     * Whether this wallet is the one the person's own account owns.
     */
    protected static function belongsTo(User $user, Wallet $wallet): bool
    {
        $account = $user->businessAccount;

        return $account !== null && $account->id === $wallet->business_account_id;
    }

    protected static function wallet(PermissionAction $action): string
    {
        return PermissionCatalogue::name(PermissionModule::Wallet, $action);
    }
}
