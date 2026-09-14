<?php

namespace App\Domain\Inventory;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Data\MovementContext;
use App\Domain\Inventory\Enums\ReservationKind;
use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Enums\StockReservationStatus;
use App\Domain\Inventory\Exceptions\InventoryRefused;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\StockReservation;
use App\Support\Concurrency\DistributedLock;
use App\Support\Concurrency\Exceptions\LockTimeout;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;

/**
 * Setting stock aside for an order, and ending that exactly once (§19.1, §36.1,
 * contract §6.1.2).
 *
 * Four operations, all through the stock ledger so every one writes its movement:
 *
 *   - **reserve** moves units from available to reserved in one warehouse.
 *   - **commit** moves them on to processing when payment is verified or the
 *     order confirmed.
 *   - **release** gives them back to available — a failed or cancelled payment,
 *     or a person's override.
 *   - **expire** gives them back when the window ran out (P3-26).
 *
 * Contract §6.1.2 asks for three things and each has a mechanism:
 *
 *   - **Under a distributed lock.** Reserving one SKU is serialised across every
 *     application server, so two orders cannot both pick the same warehouse's
 *     last unit; ending a reservation is serialised per reservation.
 *   - **Idempotent.** A reservation is found by its unique reference, so a
 *     retried reserve returns the reservation it already made, and a retried end
 *     finds the reservation already in that state and returns it — never a second
 *     movement.
 *   - **Never oversold.** The lock keeps contention down; the row lock and
 *     re-check in the ledger, and the CHECK under them, keep the figure true.
 *
 * Warehouse choice follows D15's default path: the default warehouse first, then
 * the rest in priority order, skipping any switched off, taking the first that
 * holds the whole quantity. Reassignment and manual override of the warehouse
 * belong to fulfilment (P6-32).
 */
class StockReservations
{
    public function __construct(
        protected StockLedger $ledger,
        protected DistributedLock $locks,
        protected ReservationWindows $windows,
        protected DatabaseManager $database,
    ) {}

    /**
     * Hold `$quantity` units of one SKU for the order named by `$reference`.
     *
     * @throws InventoryRefused when no active warehouse holds the whole quantity, or
     *                          the reference already reserved something else
     * @throws LockTimeout
     */
    public function reserve(
        Product $product,
        ?ProductVariant $variant,
        int $quantity,
        ReservationKind $kind,
        string $reference,
    ): StockReservation {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('A reservation holds at least one unit.');
        }

        if ($variant !== null && $variant->product_id !== $product->id) {
            throw InventoryRefused::variationNotOfProduct();
        }

        // Fast path, and the reason a retry costs nothing.
        if ($existing = $this->find($reference)) {
            return $this->sameReservation($existing, $product, $variant, $quantity, $kind);
        }

        $key = 'inventory:reserve:'.$product->id.':'.($variant->id ?? 'product');

        return $this->locks->run($key, function () use ($product, $variant, $quantity, $kind, $reference) {
            if ($existing = $this->find($reference)) {
                return $this->sameReservation($existing, $product, $variant, $quantity, $kind);
            }

            $items = StockItem::query()
                ->with('warehouse')
                ->where('product_id', $product->id)
                ->where('product_variant_id', $variant?->id)
                ->whereHas('warehouse', fn ($query) => $query->where('is_active', true))
                ->get()
                ->sortBy(fn (StockItem $item) => [
                    $item->warehouse->is_default ? 0 : 1,
                    $item->warehouse->priority,
                    $item->warehouse->id,
                ]);

            foreach ($items as $item) {
                if ($item->available < $quantity) {
                    continue;
                }

                try {
                    return $this->hold($item, $quantity, $kind, $reference);
                } catch (InventoryRefused) {
                    // Taken between the read above and the ledger's row lock;
                    // the next warehouse in order may still hold enough.
                    continue;
                } catch (UniqueConstraintViolationException $exception) {
                    $existing = $this->find($reference);

                    if ($existing === null) {
                        throw $exception;
                    }

                    return $this->sameReservation($existing, $product, $variant, $quantity, $kind);
                }
            }

            throw InventoryRefused::outOfStock(
                $variant !== null ? $variant->sku : $product->sku,
                $quantity,
                (int) $items->sum('available'),
            );
        });
    }

    /**
     * Payment verified or order confirmed: the units move on to processing.
     *
     * @throws InventoryRefused when the reservation already ended another way
     */
    public function commit(StockReservation $reservation): StockReservation
    {
        return $this->end($reservation, StockReservationStatus::Committed);
    }

    /**
     * Give the units back before the reservation ran out.
     *
     * @throws InventoryRefused when the reservation already ended another way
     */
    public function release(StockReservation $reservation, ?string $reason = null, ?int $overriddenBy = null): StockReservation
    {
        return $this->end($reservation, StockReservationStatus::Released, $reason, $overriddenBy);
    }

    /**
     * Give the units back because the window ran out.
     *
     * @throws InventoryRefused when the reservation already ended another way
     */
    public function expire(StockReservation $reservation): StockReservation
    {
        return $this->end($reservation, StockReservationStatus::Expired);
    }

    protected function hold(StockItem $item, int $quantity, ReservationKind $kind, string $reference): StockReservation
    {
        return $this->database->transaction(function () use ($item, $quantity, $kind, $reference) {
            // The reservation's id first, so its movement can name it.
            $id = (int) $this->database->scalar("SELECT nextval(pg_get_serial_sequence('stock_reservations', 'id'))");

            $this->ledger->move(
                $item,
                StockBucket::Available,
                StockBucket::Reserved,
                $quantity,
                StockMovementType::Reservation,
                new MovementContext(
                    reason: $reference,
                    sourceType: 'stock_reservation',
                    sourceId: $id,
                ),
            );

            return StockReservation::create([
                'id' => $id,
                'stock_item_id' => $item->id,
                'quantity' => $quantity,
                'kind' => $kind,
                'status' => StockReservationStatus::Active,
                'reference' => $reference,
                'expires_at' => $this->windows->expiryFor($kind),
            ]);
        });
    }

    protected function end(
        StockReservation $reservation,
        StockReservationStatus $to,
        ?string $reason = null,
        ?int $overriddenBy = null,
    ): StockReservation {
        return $this->locks->run('inventory:reservation:'.$reservation->id, fn () => $this->database->transaction(
            function () use ($reservation, $to, $reason, $overriddenBy) {
                /** @var StockReservation $locked */
                $locked = StockReservation::query()->lockForUpdate()->findOrFail($reservation->id);

                // Already ended this way: the retry is answered, nothing moves twice.
                if ($locked->status === $to) {
                    $reservation->setRawAttributes($locked->getAttributes(), sync: true);

                    return $locked;
                }

                if (! $locked->canTransitionTo($to)) {
                    throw InventoryRefused::reservationEnded($locked->status);
                }

                /** @var StockItem $item */
                $item = StockItem::query()->findOrFail($locked->stock_item_id);

                $this->ledger->move(
                    $item,
                    StockBucket::Reserved,
                    $to === StockReservationStatus::Committed ? StockBucket::Processing : StockBucket::Available,
                    $locked->quantity,
                    match ($to) {
                        StockReservationStatus::Committed => StockMovementType::ReservationCommitted,
                        StockReservationStatus::Expired => StockMovementType::ReservationExpired,
                        default => StockMovementType::ReservationReleased,
                    },
                    new MovementContext(
                        reason: $reason ?? $locked->reference,
                        actorId: $overriddenBy,
                        sourceType: 'stock_reservation',
                        sourceId: $locked->id,
                    ),
                );

                $locked->transitionTo($to);
                $locked->forceFill($to === StockReservationStatus::Committed
                    ? ['committed_at' => now()]
                    : ['released_at' => now(), 'release_reason' => $reason, 'overridden_by' => $overriddenBy],
                )->save();

                $reservation->setRawAttributes($locked->getAttributes(), sync: true);

                return $locked;
            },
        ));
    }

    /**
     * A retried reserve must be the same request, not a reference reused for
     * something else.
     *
     * @throws InventoryRefused
     */
    protected function sameReservation(
        StockReservation $existing,
        Product $product,
        ?ProductVariant $variant,
        int $quantity,
        ReservationKind $kind,
    ): StockReservation {
        $item = $existing->item;

        if ($item->product_id !== $product->id
            || $item->product_variant_id !== $variant?->id
            || $existing->quantity !== $quantity
            || $existing->kind !== $kind) {
            throw InventoryRefused::referenceInUse($existing->reference);
        }

        return $existing;
    }

    protected function find(string $reference): ?StockReservation
    {
        return StockReservation::query()->with('item')->where('reference', $reference)->first();
    }
}
