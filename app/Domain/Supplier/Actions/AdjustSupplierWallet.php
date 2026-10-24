<?php

namespace App\Domain\Supplier\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Supplier\Data\SupplierPostingContext;
use App\Domain\Supplier\Exceptions\SupplierWalletOperationRefused;
use App\Domain\Supplier\Models\SupplierLedgerEntry;
use App\Domain\Supplier\Models\SupplierWallet;
use App\Domain\Supplier\SupplierWalletService;
use App\Domain\Wallet\Enums\BalanceType;
use App\Domain\Wallet\Enums\LedgerDirection;
use App\Domain\Wallet\Policies\WalletPolicy;
use App\Models\User;
use App\Support\Money\Money;

/**
 * Moving money in a Supplier's wallet by hand — a manual top-up, or taking
 * money back (D-new).
 *
 * The Supplier counterpart of {@see CorrectLedgerEntry::adjust()}: behind
 * `wallet.adjust_wallet`, checked here as well as on the route, with a required
 * reason and a person behind it, and written to the audit log as a sensitive
 * act. A credit must say whether the money is platform credit or withdrawable —
 * never defaulted, because that is the decision about whether it can leave the
 * platform. A debit may name the kind to take first.
 *
 * Idempotent on the caller's key, so a double submit posts once.
 */
class AdjustSupplierWallet
{
    public function __construct(
        protected SupplierWalletService $wallets,
        protected RecordAuditLog $audit,
    ) {}

    /**
     * @throws SupplierWalletOperationRefused
     */
    public function handle(
        SupplierWallet $wallet,
        User $actor,
        Money $amount,
        LedgerDirection $direction,
        string $reason,
        string $idempotencyKey,
        ?BalanceType $balanceType = null,
        ?string $internalNote = null,
    ): SupplierLedgerEntry {
        if (! WalletPolicy::canAdjust($actor)) {
            throw SupplierWalletOperationRefused::notPermitted();
        }

        $entry = $this->wallets->adjust($wallet, $amount, new SupplierPostingContext(
            source: 'manual',
            description: $direction->isCredit() ? 'Manual adjustment (credit)' : 'Manual adjustment (debit)',
            idempotencyKey: 'supplier-wallet-adjustment:'.$wallet->public_id.':'.$idempotencyKey,
            reason: $reason,
            internalNote: $internalNote,
            actorId: $actor->id,
            direction: $direction,
        ), $balanceType);

        $this->audit->handle(new AuditEntry(
            action: 'supplier_wallet.manual_adjustment',
            actorId: $actor->id,
            auditableType: SupplierLedgerEntry::class,
            auditableId: $entry->id,
            after: [
                'direction' => $direction->value,
                'amount' => $amount->toDecimal(),
                'balance_type' => $balanceType?->value,
                'reference' => $entry->reference,
            ],
            reason: $reason,
            accountId: $wallet->supplier_id,
            module: 'wallet',
            // Somebody moved money by hand. That is what a reconciliation goes
            // looking for first.
            isSensitive: true,
        ));

        return $entry;
    }
}
