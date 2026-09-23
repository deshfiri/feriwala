<?php

namespace App\Domain\Supplier;

use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Inventory\Enums\ReservationKind;
use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Inventory\Enums\StockReservationStatus;
use App\Domain\Inventory\Exceptions\InventoryRefused;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Inventory\ReservationWindows;
use App\Domain\Inventory\StockReservations;
use App\Domain\Supplier\Models\SupplierOffer;
use App\Domain\Supplier\Models\SupplierOfferStock;
use Illuminate\Database\DatabaseManager;

/**
 * The Supplier-stock half of the one reservation lifecycle (D25, P13-28).
 *
 * {@see StockReservations} owns the lifecycle — the
 * reference that makes it idempotent, the distributed lock, the status machine
 * that lets a reservation end exactly once. When the units it holds belong to a
 * Supplier offer rather than a warehouse it delegates the stock movement here,
 * inside its own transaction, and everything else is unchanged.
 *
 * What this adds is the mechanism, not a second policy: lock the offer's stock
 * row, confirm the approved available quantity, move it to reserved, write the
 * movement. A reservation is one whole line from one offer — there is nothing
 * here that splits a quantity, and central stock is never read or written.
 *
 * Audit entries name the reservation and the offer's stock, never a rate or a
 * customer.
 */
class SupplierStockReservations
{
    public function __construct(
        protected SupplierStockLedger $ledger,
        protected ReservationWindows $windows,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * Set `$quantity` of one offer's approved available units aside.
     *
     * @throws InventoryRefused when the offer's stock holds fewer than that
     */
    public function hold(SupplierOffer $offer, int $quantity, ReservationKind $kind, string $reference, ?BusinessAccount $account): StockReservation
    {
        return $this->database->transaction(function () use ($offer, $quantity, $kind, $reference, $account) {
            /** @var SupplierOfferStock|null $stock */
            $stock = SupplierOfferStock::query()->lockForUpdate()->where('supplier_offer_id', $offer->id)->first();

            $available = $stock->quantity ?? 0;

            if ($stock === null || $available < $quantity) {
                throw InventoryRefused::outOfStock($offer->variant->sku ?? $offer->product->sku, $quantity, $available);
            }

            $reservation = StockReservation::create([
                'supplier_offer_stock_id' => $stock->id,
                'quantity' => $quantity,
                'kind' => $kind,
                'status' => StockReservationStatus::Active,
                'reference' => $reference,
                'expires_at' => $this->windows->expiryFor($kind),
                'business_account_id' => $account?->id,
            ]);

            $this->ledger->move(
                $stock,
                StockBucket::Available,
                StockBucket::Reserved,
                $quantity,
                'reservation',
                reservationId: $reservation->id,
                idempotencyKey: 'supplier-reservation:'.$reference,
                reason: $reference,
            );

            $this->record('supplier_stock.reserved', $stock, $reservation);

            return $reservation;
        });
    }

    /**
     * Move a locked reservation's units on, or back, as it ends.
     *
     * Called inside the reservation service's transaction with the reservation
     * already locked and already checked as still active.
     *
     * @throws InventoryRefused when the reserved bucket no longer holds the units
     */
    public function end(StockReservation $reservation, StockReservationStatus $to, ?string $reason): void
    {
        /** @var SupplierOfferStock $stock */
        $stock = SupplierOfferStock::query()->lockForUpdate()->findOrFail($reservation->supplier_offer_stock_id);

        $this->ledger->move(
            $stock,
            StockBucket::Reserved,
            $to === StockReservationStatus::Committed ? StockBucket::Processing : StockBucket::Available,
            $reservation->quantity,
            match ($to) {
                StockReservationStatus::Committed => 'reservation_committed',
                StockReservationStatus::Expired => 'reservation_expired',
                default => 'reservation_released',
            },
            reservationId: $reservation->id,
            idempotencyKey: 'supplier-reservation-end:'.$reservation->id.':'.$to->value,
            reason: $reason ?? $reservation->reference,
        );

        $this->record(
            $to === StockReservationStatus::Committed ? 'supplier_stock.committed' : 'supplier_stock.released',
            $stock,
            $reservation,
            $to->value,
        );
    }

    protected function record(string $action, SupplierOfferStock $stock, StockReservation $reservation, ?string $outcome = null): void
    {
        $this->audit->handle(new AuditEntry(
            action: $action,
            actorType: 'system',
            auditableType: SupplierOfferStock::class,
            auditableId: $stock->id,
            after: array_filter([
                'reservation' => $reservation->reference,
                'quantity' => $reservation->quantity,
                'outcome' => $outcome,
            ], fn (mixed $value) => $value !== null),
            module: PermissionModule::SupplierStock->value,
        ));
    }
}
