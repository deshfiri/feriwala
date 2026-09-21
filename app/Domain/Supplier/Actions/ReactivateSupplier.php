<?php

namespace App\Domain\Supplier\Actions;

use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Supplier\Enums\SupplierStatus;
use App\Domain\Supplier\Enums\SupplierStatusChangeSource;
use App\Domain\Supplier\Models\Supplier;
use App\Notifications\Supplier\SupplierReactivated;
use App\Support\StatusHistory\StatusChange;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Lifts a Supplier suspension (D25, P13-1). The other half of
 * {@see SuspendSupplier}.
 */
class ReactivateSupplier
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    public function handle(Supplier $supplier, int $decidedBy, string $reason, ?string $userVisibleNote = null): Supplier
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Reactivating a Supplier requires a recorded reason.');
        }

        $locked = $this->database->transaction(function () use ($supplier, $decidedBy, $reason, $userVisibleNote) {
            /** @var Supplier $locked */
            $locked = Supplier::query()->lockForUpdate()->findOrFail($supplier->id);

            if ($locked->status !== SupplierStatus::Suspended) {
                throw new InvalidArgumentException('Only a suspended Supplier can be reactivated.');
            }

            $locked->transitionWithHistory(
                SupplierStatus::Approved,
                new StatusChange(actorId: $decidedBy, reason: $reason, publicNote: $userVisibleNote),
                ['source' => SupplierStatusChangeSource::Staff],
            );

            $this->audit->handle(new AuditEntry(
                action: 'supplier.reactivated',
                actorId: $decidedBy,
                auditableType: Supplier::class,
                auditableId: $locked->id,
                before: ['status' => SupplierStatus::Suspended->value],
                after: ['status' => SupplierStatus::Approved->value],
                reason: $reason,
                note: $userVisibleNote,
                module: PermissionModule::Supplier->value,
            ));

            return $locked;
        });

        $locked->notify((new SupplierReactivated($userVisibleNote))->locale($locked->locale));

        return $locked;
    }
}
