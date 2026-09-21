<?php

namespace App\Domain\Supplier\Actions;

use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Supplier\Enums\SupplierStatus;
use App\Domain\Supplier\Enums\SupplierStatusChangeSource;
use App\Domain\Supplier\Models\Supplier;
use App\Http\Middleware\EnsureSupplierIsOperational;
use App\Notifications\Supplier\SupplierSuspended;
use App\Support\StatusHistory\StatusChange;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Suspends an approved Supplier (D25, P13-1).
 *
 * Not a permanent denial — {@see SupplierStatus::Suspended} is reversible via
 * {@see ReactivateSupplier}, mirroring D22's treatment of account suspension.
 * A suspended Supplier keeps its login and can still see its own status; only
 * {@see EnsureSupplierIsOperational} refuses it, on every
 * operational route.
 */
class SuspendSupplier
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    public function handle(Supplier $supplier, int $decidedBy, string $reason, ?string $userVisibleNote = null): Supplier
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Suspending a Supplier requires a recorded reason.');
        }

        $locked = $this->database->transaction(function () use ($supplier, $decidedBy, $reason, $userVisibleNote) {
            /** @var Supplier $locked */
            $locked = Supplier::query()->lockForUpdate()->findOrFail($supplier->id);

            if ($locked->status !== SupplierStatus::Approved) {
                throw new InvalidArgumentException('Only an approved Supplier can be suspended.');
            }

            $locked->transitionWithHistory(
                SupplierStatus::Suspended,
                new StatusChange(actorId: $decidedBy, reason: $reason, publicNote: $userVisibleNote),
                ['source' => SupplierStatusChangeSource::Staff],
            );

            $this->audit->handle(new AuditEntry(
                action: 'supplier.suspended',
                actorId: $decidedBy,
                auditableType: Supplier::class,
                auditableId: $locked->id,
                before: ['status' => SupplierStatus::Approved->value],
                after: ['status' => SupplierStatus::Suspended->value],
                reason: $reason,
                note: $userVisibleNote,
                module: PermissionModule::Supplier->value,
                isSensitive: true,
            ));

            return $locked;
        });

        $locked->notify((new SupplierSuspended($userVisibleNote))->locale($locked->locale));

        return $locked;
    }
}
