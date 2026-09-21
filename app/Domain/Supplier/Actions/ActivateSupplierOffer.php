<?php

namespace App\Domain\Supplier\Actions;

use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Supplier\Enums\OfferStatus;
use App\Domain\Supplier\Models\SupplierOffer;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Reactivates a suspended Supplier offer (D25, P13-14). The other half of
 * {@see SuspendSupplierOffer}.
 */
class ActivateSupplierOffer
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    public function handle(SupplierOffer $offer, int $decidedBy, ?string $reason = null): SupplierOffer
    {
        return $this->database->transaction(function () use ($offer, $decidedBy, $reason) {
            /** @var SupplierOffer $locked */
            $locked = SupplierOffer::query()->lockForUpdate()->findOrFail($offer->id);

            if ($locked->status !== OfferStatus::Suspended) {
                throw new InvalidArgumentException('This offer is not suspended.');
            }

            $locked->forceFill([
                'status' => OfferStatus::Active,
                'activated_by' => $decidedBy,
                'activated_at' => now(),
            ])->save();

            $this->audit->handle(new AuditEntry(
                action: 'supplier_offer.activated',
                actorId: $decidedBy,
                auditableType: SupplierOffer::class,
                auditableId: $locked->id,
                before: ['status' => OfferStatus::Suspended->value],
                after: ['status' => OfferStatus::Active->value],
                reason: $reason,
                module: PermissionModule::SupplierPricing->value,
            ));

            return $locked->refresh();
        });
    }
}
