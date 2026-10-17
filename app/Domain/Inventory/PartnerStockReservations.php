<?php

namespace App\Domain\Inventory;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Inventory\Data\MovementContext;
use App\Domain\Inventory\Enums\PartnerStockMovementType;
use App\Domain\Inventory\Enums\ReservationKind;
use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Inventory\Enums\StockReservationStatus;
use App\Domain\Inventory\Exceptions\InventoryRefused;
use App\Domain\Inventory\Models\PartnerStockItem;
use App\Domain\Inventory\Models\StockReservation;
use Illuminate\Database\DatabaseManager;

/**
 * The Partner-stock half of the one reservation lifecycle (D34).
 *
 * {@see StockReservations} owns the lifecycle — the reference that makes it
 * idempotent, the distributed lock, the status machine that lets a reservation
 * end exactly once. When the units it holds are a Partner's own it delegates the
 * stock movement here, inside its own transaction, and everything else is
 * unchanged. Central stock and Supplier stock are never read or written.
 *
 * A reservation is one whole line from the one Partner stock row: there is no
 * splitting, and nothing is ever taken from another Partner or another
 * Variation.
 */
class PartnerStockReservations
{
    public function __construct(
        protected PartnerStock $stock,
        protected ReservationWindows $windows,
        protected DatabaseManager $database,
    ) {}

    /**
     * Set `$quantity` of the Partner's available units aside.
     *
     * @throws InventoryRefused when the Partner has fewer available than that
     */
    public function hold(
        BusinessAccount $account,
        int $productId,
        ?int $variantId,
        string $sku,
        int $quantity,
        ReservationKind $kind,
        string $reference,
    ): StockReservation {
        return $this->database->transaction(function () use ($account, $productId, $variantId, $sku, $quantity, $kind, $reference) {
            $item = $this->stock->itemFor($account, $productId, $variantId);

            // Locked before it is read, so the figure checked is the true one.
            $locked = $item === null ? null : PartnerStockItem::query()->lockForUpdate()->find($item->id);
            $available = $locked->available ?? 0;

            if ($locked === null || $available < $quantity) {
                throw InventoryRefused::outOfStock($sku, $quantity, $available);
            }

            $reservation = StockReservation::create([
                'partner_stock_item_id' => $locked->id,
                'quantity' => $quantity,
                'kind' => $kind,
                'status' => StockReservationStatus::Active,
                'reference' => $reference,
                'expires_at' => $this->windows->expiryFor($kind),
                'business_account_id' => $account->id,
            ]);

            $this->stock->move(
                $locked,
                StockBucket::Available,
                StockBucket::Reserved,
                $quantity,
                PartnerStockMovementType::Reservation,
                new MovementContext(
                    reason: $reference,
                    sourceType: 'stock_reservation',
                    sourceId: $reservation->id,
                    idempotencyKey: 'partner-reservation:'.$reference,
                ),
            );

            return $reservation;
        });
    }

    /**
     * Whether `$quantity` units are available right now — read-only, holding
     * nothing. Advisory: the hold above remains the binding check.
     */
    public function canHold(BusinessAccount $account, int $productId, ?int $variantId, int $quantity): bool
    {
        if ($quantity < 1) {
            return false;
        }

        $available = $this->stock->availableFor($account, [['product_id' => $productId, 'variant_id' => $variantId]]);

        return ($available[$productId.':'.($variantId ?? '')] ?? 0) >= $quantity;
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
        /** @var PartnerStockItem $item */
        $item = PartnerStockItem::query()->lockForUpdate()->findOrFail($reservation->partner_stock_item_id);

        $this->stock->move(
            $item,
            StockBucket::Reserved,
            $to === StockReservationStatus::Committed ? StockBucket::Processing : StockBucket::Available,
            $reservation->quantity,
            match ($to) {
                StockReservationStatus::Committed => PartnerStockMovementType::ReservationCommitted,
                StockReservationStatus::Expired => PartnerStockMovementType::ReservationExpired,
                default => PartnerStockMovementType::ReservationReleased,
            },
            new MovementContext(
                reason: $reason ?? $reservation->reference,
                sourceType: 'stock_reservation',
                sourceId: $reservation->id,
                idempotencyKey: 'partner-reservation-end:'.$reservation->id.':'.$to->value,
            ),
        );
    }
}
