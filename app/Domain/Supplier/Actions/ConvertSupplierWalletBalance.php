<?php

namespace App\Domain\Supplier\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Supplier\Data\SupplierPostingContext;
use App\Domain\Supplier\Exceptions\SupplierWalletOperationRefused;
use App\Domain\Supplier\Models\SupplierLedgerEntry;
use App\Domain\Supplier\Models\SupplierWallet;
use App\Domain\Supplier\SupplierWalletService;
use App\Domain\Wallet\Actions\ConvertWalletBalance;
use App\Domain\Wallet\Policies\WalletPolicy;
use App\Models\User;
use App\Support\Money\Money;

/**
 * Turns a Supplier's platform credit into withdrawable money (D-new).
 *
 * The Supplier counterpart of {@see ConvertWalletBalance}, with the same
 * guarantees: its own permission, checked in the action, a required reason, one
 * atomic pair of entries sharing a conversion reference, an audit record, and a
 * double submit that converts once. There is no reverse.
 */
class ConvertSupplierWalletBalance
{
    public function __construct(
        protected SupplierWalletService $wallets,
        protected RecordAuditLog $audit,
    ) {}

    /**
     * @return array{debit: SupplierLedgerEntry, credit: SupplierLedgerEntry}
     *
     * @throws SupplierWalletOperationRefused
     */
    public function handle(SupplierWallet $wallet, User $actor, Money $amount, string $reason, string $idempotencyKey): array
    {
        if (! WalletPolicy::canConvertBalance($actor)) {
            throw SupplierWalletOperationRefused::notPermitted();
        }

        $entries = $this->wallets->convertToWithdrawable($wallet, $amount, new SupplierPostingContext(
            source: 'balance_conversion',
            description: 'Platform credit converted to withdrawable',
            idempotencyKey: 'supplier-wallet-conversion:'.$wallet->public_id.':'.$idempotencyKey,
            reason: $reason,
            actorId: $actor->id,
        ));

        $this->audit->handle(new AuditEntry(
            action: 'supplier_wallet.balance_converted',
            actorId: $actor->id,
            auditableType: SupplierLedgerEntry::class,
            auditableId: $entries['debit']->id,
            after: [
                'amount' => $amount->toDecimal(),
                'from' => 'platform_credit',
                'to' => 'withdrawable',
                'conversion_reference' => $entries['debit']->conversion_reference,
                'debit_reference' => $entries['debit']->reference,
                'credit_reference' => $entries['credit']->reference,
            ],
            reason: $reason,
            accountId: $wallet->supplier_id,
            module: 'wallet',
            isSensitive: true,
        ));

        return $entries;
    }
}
