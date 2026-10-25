<?php

namespace App\Domain\Wallet;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Wallet\Models\Wallet;
use App\Support\Money\Money;

/**
 * Paying for an order out of the account's own wallet.
 *
 * Not a gateway: nothing is asked of a provider and nothing is configured. It is
 * named like one only so that an order's payment can say how it was paid, beside
 * the gateways it is chosen from.
 *
 * Platform credit is spent first and withdrawable money after it, exactly as any
 * other spend from the wallet is — see {@see WalletService::debit()}.
 */
final class WalletPaymentMethod
{
    public const NAME = 'wallet';

    public static function is(?string $method): bool
    {
        return $method === self::NAME;
    }

    public static function label(): string
    {
        return __('wallet.pay.method');
    }

    public static function walletFor(BusinessAccount $account): ?Wallet
    {
        return Wallet::query()->where('business_account_id', $account->id)->first();
    }

    /**
     * What the wallet could spend right now, or null when the account has no
     * wallet or it holds another currency than the payment.
     */
    public static function spendable(BusinessAccount $account, Money $due): ?Money
    {
        $wallet = self::walletFor($account);

        if ($wallet === null) {
            return null;
        }

        $available = $wallet->usableBalance();

        return $available->currency === $due->currency ? $available : null;
    }

    public static function canCover(BusinessAccount $account, Money $due): bool
    {
        // Nothing owed is covered by any wallet, or none.
        if (! $due->isPositive()) {
            return true;
        }

        $available = self::spendable($account, $due);

        return $available !== null && ! $available->lessThan($due);
    }

    /**
     * The wallet as a choice on a payment screen.
     *
     * @return array{name: string, label: string, fx: null, balance: array<string, mixed>|null, sufficient: bool}
     */
    public static function offer(BusinessAccount $account, Money $due): array
    {
        $available = self::spendable($account, $due);

        return [
            'name' => self::NAME,
            'label' => self::label(),
            'fx' => null,
            'balance' => $available?->jsonSerialize(),
            'sufficient' => self::canCover($account, $due),
        ];
    }
}
