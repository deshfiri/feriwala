<?php

namespace App\Domain\Supplier\Actions;

use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Supplier\Models\SupplierOffer;
use App\Domain\Supplier\Models\SupplierOfferPriceChange;
use App\Support\Money\Money;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Records a new, effective-dated Supplier Rate and Platform Rate for one
 * offer (D25, P13-14, P13-15).
 *
 * Never edits a past rate — {@see SupplierOfferPriceChange}
 * is append-only, enforced by the database as well as here — and never
 * touches another Supplier's offer, however many others sell the same
 * variation. `supplier_offers` keeps only the current figures, denormalised
 * for fast reads; the history table is the ledger of how they got there.
 */
class SetSupplierOfferRates
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    public function handle(SupplierOffer $offer, int $reviewerId, Money $supplierRate, Money $platformRate, string $reason): SupplierOffer
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A reason is required and is recorded against every rate change.');
        }

        if ($supplierRate->currency !== $platformRate->currency) {
            throw new InvalidArgumentException('The Supplier Rate and Platform Rate must use the same currency.');
        }

        if ($supplierRate->isNegative() || $platformRate->isNegative()) {
            throw new InvalidArgumentException('Neither rate may be negative.');
        }

        if ($platformRate->lessThan($supplierRate)) {
            throw new InvalidArgumentException('The Platform Rate cannot be lower than the Supplier Rate.');
        }

        return $this->database->transaction(function () use ($offer, $reviewerId, $supplierRate, $platformRate, $reason) {
            /** @var SupplierOffer $locked */
            $locked = SupplierOffer::query()->lockForUpdate()->findOrFail($offer->id);

            $before = ['supplier_rate_minor' => $locked->supplier_rate_minor->minorUnits, 'platform_rate_minor' => $locked->platform_rate_minor->minorUnits];

            $locked->forceFill([
                'supplier_rate_minor' => $supplierRate->minorUnits,
                'platform_rate_minor' => $platformRate->minorUnits,
                'currency_code' => $supplierRate->currency->value,
            ])->save();

            $locked->priceHistory()->create([
                'supplier_rate_minor' => $supplierRate->minorUnits,
                'platform_rate_minor' => $platformRate->minorUnits,
                'currency_code' => $supplierRate->currency->value,
                'effective_from' => now(),
                'changed_by' => $reviewerId,
                'reason' => $reason,
                'created_at' => now(),
            ]);

            $this->audit->handle(new AuditEntry(
                action: 'supplier_offer.rates_changed',
                actorId: $reviewerId,
                auditableType: SupplierOffer::class,
                auditableId: $locked->id,
                before: $before,
                after: ['supplier_rate_minor' => $supplierRate->minorUnits, 'platform_rate_minor' => $platformRate->minorUnits],
                reason: $reason,
                module: PermissionModule::SupplierPricing->value,
            ));

            return $locked->refresh();
        });
    }
}
