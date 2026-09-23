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
 * Reject a requested withdrawal, or mark a processing one failed (D25, P13-24).
 *
 * Both release the reservation {@see RequestSupplierWithdrawal} made —
 * exactly once, because the wallet is locked first and the withdrawal's own
 * status is what stops a second attempt: once it is `Rejected` or `Failed`
 * neither state transitions anywhere, so a repeat finds the illegal move and
 * refuses before the wallet is ever touched again.
 */
class RejectOrFailSupplierWithdrawal
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
        protected SupplierWalletService $wallets,
    ) {}

    public function handle(
        SupplierWithdrawal $withdrawal,
        SupplierWithdrawalStatus $to,
        string $reason,
        int $actorId,
    ): SupplierWithdrawal {
        return $this->database->transaction(function () use ($withdrawal, $to, $reason, $actorId) {
            /** @var SupplierWithdrawal $locked */
            $locked = SupplierWithdrawal::query()->lockForUpdate()->findOrFail($withdrawal->id);

            if (! $locked->canTransitionTo($to)) {
                throw SupplierWithdrawalRefused::illegalTransition($locked->reference, $locked->status->label());
            }

            $wallet = $locked->wallet()->lockForUpdate()->firstOrFail();

            $this->wallets->release($wallet, $locked->amount, new SupplierPostingContext(
                source: 'withdrawal',
                description: "Reservation released for withdrawal {$locked->reference}",
                idempotencyKey: 'supplier-withdrawal-release:'.$locked->public_id,
                reason: $reason,
                supplierWithdrawalId: $locked->id,
            ));

            if ($to === SupplierWithdrawalStatus::Failed) {
                $locked->forceFill(['failure_reason' => $reason])->save();
            }

            $locked->transitionWithHistory(
                $to,
                new StatusChange(actorId: $actorId, reason: $reason),
                ['source' => SupplierWithdrawalChangeSource::Staff],
            );

            $this->audit->handle(new AuditEntry(
                action: 'supplier_withdrawal.'.$to->value,
                actorId: $actorId,
                auditableType: SupplierWithdrawal::class,
                auditableId: $locked->id,
                after: ['status' => $to->value],
                reason: $reason,
                accountId: $locked->supplier_id,
                module: PermissionModule::Withdrawal->value,
            ));

            $locked->supplier->notify(
                (new SupplierWithdrawalStatusChanged($locked, $reason))->locale($locked->supplier->locale)
            );

            return $locked;
        });
    }
}
