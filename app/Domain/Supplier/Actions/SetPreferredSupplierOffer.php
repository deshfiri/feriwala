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
 * Chooses which one Supplier offer is used for Client/Partner purchasing on
 * a product or variation with more than one (D25, P13-13, P13-14).
 *
 * **A deliberate, Admin-made choice — never automatic.** There is no
 * cheapest-Supplier algorithm here or anywhere else in this batch. Only one
 * offer per (product, variation) may be preferred at a time, enforced by the
 * partial unique index `supplier_offers_one_preferred_per_variation` as well
 * as by unsetting the previous one in the same transaction.
 *
 * This is also the beta's whole catalogue integration point: making an offer
 * preferred writes its **Platform Rate only** — never the Supplier Rate, and
 * never the Supplier's identity — into the connected product's or variant's
 * own `wholesale_price`, which is the column the existing, unmodified
 * `App\Http\Controllers\Erp\CatalogController` already reads for every
 * Client/Partner. Nothing about the wholesale/dropshipping browsing or
 * eligibility logic is duplicated or touched.
 */
class SetPreferredSupplierOffer
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    public function handle(SupplierOffer $offer, int $decidedBy): SupplierOffer
    {
        return $this->database->transaction(function () use ($offer, $decidedBy) {
            /** @var SupplierOffer $locked */
            $locked = SupplierOffer::query()->lockForUpdate()->findOrFail($offer->id);

            if ($locked->status !== OfferStatus::Active) {
                throw new InvalidArgumentException('Only an active offer can be made preferred.');
            }

            SupplierOffer::query()
                ->where('product_id', $locked->product_id)
                ->where(fn ($query) => $locked->product_variant_id === null
                    ? $query->whereNull('product_variant_id')
                    : $query->where('product_variant_id', $locked->product_variant_id))
                ->where('is_preferred', true)
                ->where('id', '!=', $locked->id)
                ->update(['is_preferred' => false]);

            $locked->forceFill(['is_preferred' => true])->save();

            if ($locked->product_variant_id !== null) {
                $locked->variant()->update(['wholesale_price' => $locked->platform_rate->toDecimal()]);
            } else {
                $locked->product()->update(['wholesale_price' => $locked->platform_rate->toDecimal()]);
            }

            $this->audit->handle(new AuditEntry(
                action: 'supplier_offer.preferred_selected',
                actorId: $decidedBy,
                auditableType: SupplierOffer::class,
                auditableId: $locked->id,
                after: ['product_id' => $locked->product_id, 'product_variant_id' => $locked->product_variant_id],
                module: PermissionModule::SupplierPricing->value,
            ));

            return $locked->refresh();
        });
    }
}
