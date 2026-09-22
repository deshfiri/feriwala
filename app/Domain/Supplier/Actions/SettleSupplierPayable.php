<?php

namespace App\Domain\Supplier\Actions;

use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Supplier\Data\SupplierPostingContext;
use App\Domain\Supplier\Enums\PayableChangeSource;
use App\Domain\Supplier\Enums\PayableStatus;
use App\Domain\Supplier\Exceptions\SupplierPayableSettlementRefused;
use App\Domain\Supplier\Models\SupplierPayable;
use App\Domain\Supplier\SupplierWalletService;
use App\Notifications\Supplier\SupplierPayableSettled;
use App\Support\Money\Currency;
use App\Support\StatusHistory\StatusChange;
use Illuminate\Database\DatabaseManager;

/**
 * Pay an `Eligible` Supplier payable into the Supplier's own wallet (D25, P13-23).
 *
 * The payable row is locked first, and that lock is what makes two workers
 * settling the same payable produce one credit: the second to arrive waits
 * for the first to commit, then finds `settled_at` already set and returns
 * the same result rather than crediting again. The wallet-level idempotency
 * key on the ledger entry is a second, independent guard against the same
 * outcome.
 *
 * Only `Eligible` payables settle. A payable that was reversed before it ever
 * settled moves to `PartiallyReversed` or `Reversed` and stays there — it
 * does not settle its remaining net amount in this batch, even though the
 * state machine still permits the move (a deliberate, smaller scope than the
 * enum alone would suggest).
 */
class SettleSupplierPayable
{
    public function __construct(
        protected SupplierWalletService $wallets,
        protected OpenSupplierWallet $openWallet,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    public function handle(SupplierPayable $payable, ?int $actorId = null): SupplierPayable
    {
        return $this->database->transaction(function () use ($payable, $actorId) {
            /** @var SupplierPayable $locked */
            $locked = SupplierPayable::query()->lockForUpdate()->findOrFail($payable->id);

            if ($locked->settled_at !== null) {
                return $locked;
            }

            if ($locked->status !== PayableStatus::Eligible) {
                throw SupplierPayableSettlementRefused::notEligible($locked);
            }

            if ($locked->delivered_at === null || $locked->payment_settled_at === null) {
                throw SupplierPayableSettlementRefused::notEarned($locked);
            }

            $netAmount = $locked->netAmount();
            $key = 'supplier-payable-settlement:'.$locked->public_id;

            $wallet = $this->openWallet->handle($locked->supplier, Currency::from($locked->currency_code));

            $entry = $this->wallets->credit($wallet, $netAmount, new SupplierPostingContext(
                source: 'payable_settlement',
                description: "Settlement for payable {$locked->reference}",
                idempotencyKey: $key,
                supplierPayableId: $locked->id,
            ));

            $locked->forceFill([
                'settled_at' => now(),
                'settlement_reference' => $entry->reference,
            ])->save();

            $locked->transitionWithHistory(
                PayableStatus::Settled,
                new StatusChange(actorId: $actorId),
                ['source' => PayableChangeSource::Staff],
            );

            $this->audit->handle(new AuditEntry(
                action: 'supplier_payable.settled',
                actorId: $actorId,
                auditableType: SupplierPayable::class,
                auditableId: $locked->id,
                after: [
                    'amount_minor' => $netAmount->minorUnits,
                    'settlement_reference' => $entry->reference,
                ],
                accountId: $locked->supplier_id,
                module: PermissionModule::SupplierPayable->value,
            ));

            $locked->supplier->notify(
                (new SupplierPayableSettled($locked, $netAmount))->locale($locked->supplier->locale)
            );

            return $locked;
        });
    }
}
