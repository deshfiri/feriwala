<?php

namespace App\Domain\Supplier\Actions;

use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Supplier\Data\SupplierPostingContext;
use App\Domain\Supplier\Enums\SupplierWithdrawalChangeSource;
use App\Domain\Supplier\Enums\SupplierWithdrawalStatus;
use App\Domain\Supplier\Exceptions\SupplierWithdrawalRefused;
use App\Domain\Supplier\Models\SupplierWithdrawal;
use App\Domain\Supplier\SupplierWalletService;
use App\Notifications\Supplier\SupplierWithdrawalStatusChanged;
use App\Support\StatusHistory\StatusChange;
use Illuminate\Database\DatabaseManager;

/**
 * Pay a Processing withdrawal (D25, P13-24).
 *
 * Consumes the reservation exactly once: the wallet's `reserved` and `total`
 * fall together in one ledger entry, and the withdrawal's own state machine
 * — locked first — is what stops a retry from paying twice. Requires the
 * external reference the payout channel returned; the database itself
 * refuses a `Paid` row without one (`supplier_withdrawals_paid_is_complete`).
 */
class PaySupplierWithdrawal
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
        protected SupplierWalletService $wallets,
    ) {}

    public function handle(
        SupplierWithdrawal $withdrawal,
        string $externalReference,
        int $actorId,
    ): SupplierWithdrawal {
        return $this->database->transaction(function () use ($withdrawal, $externalReference, $actorId) {
            /** @var SupplierWithdrawal $locked */
            $locked = SupplierWithdrawal::query()->lockForUpdate()->findOrFail($withdrawal->id);

            if (! $locked->canTransitionTo(SupplierWithdrawalStatus::Paid)) {
                throw SupplierWithdrawalRefused::illegalTransition($locked->reference, $locked->status->label());
            }

            $wallet = $locked->wallet()->lockForUpdate()->firstOrFail();

            $this->wallets->pay($wallet, $locked->amount_minor, new SupplierPostingContext(
                source: 'withdrawal',
                description: "Withdrawal {$locked->reference} paid",
                idempotencyKey: 'supplier-withdrawal-paid:'.$locked->public_id,
                supplierWithdrawalId: $locked->id,
            ));

            $locked->forceFill([
                'processed_by' => $actorId,
                'processed_at' => $locked->processed_at ?? now(),
                'paid_at' => now(),
                'external_reference' => $externalReference,
            ])->save();

            $locked->transitionWithHistory(
                SupplierWithdrawalStatus::Paid,
                new StatusChange(actorId: $actorId),
                ['source' => SupplierWithdrawalChangeSource::Staff],
            );

            $this->audit->handle(new AuditEntry(
                action: 'supplier_withdrawal.paid',
                actorId: $actorId,
                auditableType: SupplierWithdrawal::class,
                auditableId: $locked->id,
                after: ['status' => 'paid', 'external_reference' => $externalReference],
                accountId: $locked->supplier_id,
                module: PermissionModule::Withdrawal->value,
                isSensitive: true,
            ));

            $locked->supplier->notify(
                (new SupplierWithdrawalStatusChanged($locked))->locale($locked->supplier->locale)
            );

            return $locked;
        });
    }
}
