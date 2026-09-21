<?php

namespace App\Domain\Supplier\Actions;

use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Supplier\Enums\OfferStatus;
use App\Domain\Supplier\Models\SupplierOffer;
use App\Notifications\Supplier\SupplierOfferSuspended;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Takes one Supplier offer out of purchase (D25, P13-14).
 *
 * Scoped to this offer alone — suspending it never touches another
 * Supplier's offer on the same product or variation, and never removes the
 * catalogue connection, only the ability to buy through this particular
 * offer.
 */
class SuspendSupplierOffer
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    public function handle(SupplierOffer $offer, int $decidedBy, ?string $reason = null): SupplierOffer
    {
        $locked = $this->database->transaction(function () use ($offer, $decidedBy, $reason) {
            /** @var SupplierOffer $locked */
            $locked = SupplierOffer::query()->lockForUpdate()->findOrFail($offer->id);

            if ($locked->status !== OfferStatus::Active) {
                throw new InvalidArgumentException('This offer is not active.');
            }

            $locked->forceFill([
                'status' => OfferStatus::Suspended,
                'suspended_by' => $decidedBy,
                'suspended_at' => now(),
                'is_preferred' => false,
            ])->save();

            $this->audit->handle(new AuditEntry(
                action: 'supplier_offer.suspended',
                actorId: $decidedBy,
                auditableType: SupplierOffer::class,
                auditableId: $locked->id,
                before: ['status' => OfferStatus::Active->value],
                after: ['status' => OfferStatus::Suspended->value],
                reason: $reason,
                module: PermissionModule::SupplierPricing->value,
            ));

            return $locked;
        });

        $locked->supplier->notify((new SupplierOfferSuspended($locked, $reason))->locale($locked->supplier->locale));

        return $locked;
    }
}
