<?php

namespace App\Domain\Supplier\Actions;

use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierWallet;
use App\Domain\Wallet\WalletService;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Exactly one Supplier wallet per Supplier per currency (D25, P13-23).
 *
 * A Supplier is never attached to a Business Account and never shares a
 * wallet with one — see `2026_10_04_100000_take_supplier_wallets`'s own
 * doc-comment for why. `supplier_wallets` enforces the one-per-currency rule
 * itself with a unique index; this only makes "get or create" race-safe on
 * top of it, the same way {@see WalletService} relies on a
 * unique key rather than a lock to settle a race between two callers opening
 * the same wallet for the first time.
 */
class OpenSupplierWallet
{
    public function handle(Supplier $supplier, Currency $currency): SupplierWallet
    {
        $existing = SupplierWallet::query()
            ->where('supplier_id', $supplier->id)
            ->where('currency_code', $currency->value)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $zero = Money::zero($currency);

        try {
            return SupplierWallet::create([
                'supplier_id' => $supplier->id,
                'currency_code' => $currency->value,
                'total' => $zero,
                'reserved' => $zero,
                'recovery' => $zero,
            ]);
        } catch (UniqueConstraintViolationException) {
            /** @var SupplierWallet */
            return SupplierWallet::query()
                ->where('supplier_id', $supplier->id)
                ->where('currency_code', $currency->value)
                ->firstOrFail();
        }
    }
}
