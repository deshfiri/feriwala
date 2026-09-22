<?php

namespace App\Domain\Supplier\Actions;

use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Enums\ReservationKind;
use App\Domain\Inventory\Exceptions\InventoryRefused;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Inventory\StockReservations;
use App\Domain\Supplier\Data\SupplierAllocation;
use App\Domain\Supplier\Exceptions\SupplierAllocationRefused;
use App\Domain\Supplier\Queries\ResolvePreferredOffer;
use App\Support\Concurrency\Exceptions\LockTimeout;
use App\Support\Money\Currency;

/**
 * Resolve one order line's Supplier offer and hold its stock, in the one step
 * an order-placement action calls (D25, P13-21, P13-28).
 *
 * Everything about which Supplier serves a line is decided here, entirely on
 * the server, from the catalogue's own preferred-offer selection — never from
 * anything a browser or a Storefront sent. A variation with no Supplier offer
 * at all resolves to the unchanged central-stock path; one that has any offer
 * is Supplier-sourced from then on, and either its preferred offer serves the
 * whole line or the line is refused — never a fallback to another Supplier,
 * and never a split between sources.
 */
class AllocateSupplierOrderLine
{
    public function __construct(
        protected ResolvePreferredOffer $offers,
        protected StockReservations $reservations,
        protected RecordAuditLog $audit,
    ) {}

    /**
     * @return array{0: StockReservation, 1: SupplierAllocation|null}
     *
     * @throws SupplierAllocationRefused
     * @throws InventoryRefused
     * @throws LockTimeout
     */
    public function handle(
        Product $product,
        ?ProductVariant $variant,
        int $quantity,
        Currency $currency,
        ReservationKind $kind,
        string $reference,
        ?BusinessAccount $account = null,
    ): array {
        $allocation = $this->offers->resolve($product, $variant, $quantity, $currency);

        if ($allocation === null) {
            return [$this->reservations->reserve($product, $variant, $quantity, $kind, $reference, $account), null];
        }

        $reservation = $this->reservations->reserveFromSupplier($allocation->offer, $quantity, $kind, $reference, $account);

        $this->audit->handle(new AuditEntry(
            action: 'supplier_offer.allocated',
            auditableType: $allocation->offer::class,
            auditableId: $allocation->offer->id,
            after: [
                'reservation' => $reservation->reference,
                'quantity' => $quantity,
            ],
            module: PermissionModule::SupplierStock->value,
        ));

        return [$reservation, $allocation];
    }
}
