<?php

namespace App\Domain\Supplier\Actions;

use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Supplier\Enums\SupplierWithdrawalChangeSource;
use App\Domain\Supplier\Enums\SupplierWithdrawalStatus;
use App\Domain\Supplier\Exceptions\SupplierWithdrawalRefused;
use App\Domain\Supplier\Models\SupplierWithdrawal;
use App\Support\StatusHistory\StatusChange;
use Illuminate\Database\DatabaseManager;

/**
 * Move a Supplier withdrawal forward without moving any money (D25, P13-24):
 * Requested → Under review, Under review → Approved, Approved → Processing.
 *
 * No reservation changes here — money is set aside once, at request time, and
 * given back or paid exactly once, by {@see RejectOrFailSupplierWithdrawal}
 * and {@see PaySupplierWithdrawal} respectively. This only records that a
 * person looked at the request and let it through.
 */
class AdvanceSupplierWithdrawalStatus
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    public function handle(
        SupplierWithdrawal $withdrawal,
        SupplierWithdrawalStatus $to,
        int $actorId,
        ?string $note = null,
    ): SupplierWithdrawal {
        return $this->database->transaction(function () use ($withdrawal, $to, $actorId, $note) {
            /** @var SupplierWithdrawal $locked */
            $locked = SupplierWithdrawal::query()->lockForUpdate()->findOrFail($withdrawal->id);

            if (! $locked->canTransitionTo($to)) {
                throw SupplierWithdrawalRefused::illegalTransition($locked->reference, $locked->status->label());
            }

            $locked->transitionWithHistory(
                $to,
                new StatusChange(actorId: $actorId, publicNote: $note),
                ['source' => SupplierWithdrawalChangeSource::Staff],
            );

            $this->audit->handle(new AuditEntry(
                action: 'supplier_withdrawal.'.$to->value,
                actorId: $actorId,
                auditableType: SupplierWithdrawal::class,
                auditableId: $locked->id,
                after: ['status' => $to->value],
                accountId: $locked->supplier_id,
                module: PermissionModule::Withdrawal->value,
            ));

            return $locked;
        });
    }
}
