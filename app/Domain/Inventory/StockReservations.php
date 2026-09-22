<?php

namespace App\Domain\Inventory;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Data\MovementContext;
use App\Domain\Inventory\Enums\ReservationKind;
use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Enums\StockReservationStatus;
use App\Domain\Inventory\Exceptions\InventoryRefused;
use App\Domain\Inventory\Models\StockAllocation;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Supplier\Models\SupplierOffer;
use App\Domain\Supplier\SupplierStockReservations;
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
 *
 * **User-allocated stock (P3-30).** An order placed for a business account first
 * draws on stock allocated to that account — in the same warehouse order — and
 * only then on shared available stock; never on another account's allocation,
 * and never partly from each, because a reservation is never split. A
 * reservation that drew on an allocation remembers it, and releasing or expiring
 * it gives the units back to that allocation rather than to everyone. Lock order
 * matches the allocation service: the stock item, then the allocation.
 */
class StockReservations
{
    public function __construct(
        protected StockLedger $ledger,
        protected DistributedLock $locks,
        protected ReservationWindows $windows,
        protected DatabaseManager $database,
        protected SupplierStockReservations $supplierStock,
    ) {}

    /**
     * Hold `$quantity` units of one Supplier offer's approved availability for
     * the order named by `$reference` (D25, P13-28).
     *
     * The same lifecycle as {@see reserve()} with a different source of units:
     * the reference makes it idempotent, one lock per offer's stock serialises
     * racing orders, and commit, release and expire below end it exactly once.
     * Never partly central and never across offers — a line is one whole
     * quantity from the one offer the allocation named.
     *
     * @throws InventoryRefused when the offer's stock holds fewer than that, or the
     *                          reference already reserved something else
     * @throws LockTimeout
     */
    public function reserveFromSupplier(
        SupplierOffer $offer,
        int $quantity,
        ReservationKind $kind,
        string $reference,
        ?BusinessAccount $account = null,
    ): StockReservation {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('A reservation holds at least one unit.');
        }

        if ($existing = $this->find($reference)) {
            return $this->sameSupplierReservation($existing, $offer, $quantity, $kind, $account);
        }

        return $this->locks->run('inventory:reserve:supplier-offer:'.$offer->id, function () use ($offer, $quantity, $kind, $reference, $account) {
            if ($existing = $this->find($reference)) {
                return $this->sameSupplierReservation($existing, $offer, $quantity, $kind, $account);
            }

            try {
                return $this->supplierStock->hold($offer, $quantity, $kind, $reference, $account);
            } catch (UniqueConstraintViolationException $exception) {
                $existing = $this->find($reference);

                if ($existing === null) {
                    throw $exception;
                }

                return $this->sameSupplierReservation($existing, $offer, $quantity, $kind, $account);
            }
        });
    }

    /**
     * Hold `$quantity` units of one SKU for the order named by `$reference`.
     *
     * `$account` is the business account the order is for, when it is for one:
     * its allocated stock is drawn on first (P3-30).
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
        ?BusinessAccount $account = null,
    ): StockReservation {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('A reservation holds at least one unit.');
        }

        if ($variant !== null && $variant->product_id !== $product->id) {
            throw InventoryRefused::variationNotOfProduct();
        }

        // Fast path, and the reason a retry costs nothing.
        if ($existing = $this->find($reference)) {
            return $this->sameReservation($existing, $product, $variant, $quantity, $kind, $account);
        }

        $key = 'inventory:reserve:'.$product->id.':'.($variant->id ?? 'product');

        return $this->locks->run($key, function () use ($product, $variant, $quantity, $kind, $reference, $account) {
            if ($existing = $this->find($reference)) {
                return $this->sameReservation($existing, $product, $variant, $quantity, $kind, $account);
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

            // What the ordering account has set aside, per item. Nobody else's.
            $allocations = $account === null
                ? collect()
                : StockAllocation::query()
                    ->where('business_account_id', $account->id)
                    ->whereIn('stock_item_id', $items->pluck('id'))
                    ->get()
                    ->keyBy('stock_item_id');

            $attempt = function (StockItem $item, ?StockAllocation $allocation) use ($product, $variant, $quantity, $kind, $reference, $account): ?StockReservation {
                try {
                    return $this->hold($item, $quantity, $kind, $reference, $account, $allocation);
                } catch (InventoryRefused) {
                    // Taken between the read above and the row lock; the next
                    // warehouse in order may still hold enough.
                    return null;
                } catch (UniqueConstraintViolationException $exception) {
                    $existing = $this->find($reference);

                    if ($existing === null) {
                        throw $exception;
                    }

                    return $this->sameReservation($existing, $product, $variant, $quantity, $kind, $account);
                }
            };

            // The account's own allocation first, in warehouse order…
            foreach ($items as $item) {
                /** @var StockAllocation|null $allocation */
                $allocation = $allocations->get($item->id);

                if ($allocation !== null && $allocation->quantity >= $quantity
                    && ($reservation = $attempt($item, $allocation)) !== null) {
                    return $reservation;
                }
            }

            // …then stock anyone may buy.
            foreach ($items as $item) {
                if ($item->available >= $quantity && ($reservation = $attempt($item, null)) !== null) {
                    return $reservation;
                }
            }

            throw InventoryRefused::outOfStock(
                $variant !== null ? $variant->sku : $product->sku,
                $quantity,
                (int) $items->sum('available') + (int) $allocations->sum('quantity'),
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

    /**
     * @throws InventoryRefused when the bucket or allocation no longer holds enough
     * @throws UniqueConstraintViolationException when the reference was reserved by a racing request
     */
    protected function hold(
        StockItem $item,
        int $quantity,
        ReservationKind $kind,
        string $reference,
        ?BusinessAccount $account = null,
        ?StockAllocation $allocation = null,
    ): StockReservation {
        return $this->database->transaction(function () use ($item, $quantity, $kind, $reference, $account, $allocation) {
            $lockedAllocation = null;

            if ($allocation !== null) {
                // The item, then the allocation — the order every allocation path uses.
                StockItem::query()->lockForUpdate()->findOrFail($item->id);

                /** @var StockAllocation $lockedAllocation */
                $lockedAllocation = StockAllocation::query()->lockForUpdate()->findOrFail($allocation->id);

                if ($lockedAllocation->quantity < $quantity) {
                    throw InventoryRefused::allocationInsufficient($lockedAllocation->quantity, $quantity);
                }
            }

            // The reservation's id first, so its movement can name it.
            $id = (int) $this->database->scalar("SELECT nextval(pg_get_serial_sequence('stock_reservations', 'id'))");

            $this->ledger->move(
                $item,
                $lockedAllocation !== null ? StockBucket::Allocated : StockBucket::Available,
                StockBucket::Reserved,
                $quantity,
                StockMovementType::Reservation,
                new MovementContext(
                    reason: $reference,
                    sourceType: 'stock_reservation',
                    sourceId: $id,
                ),
            );

            $lockedAllocation?->forceFill(['quantity' => $lockedAllocation->quantity - $quantity])->save();

            return StockReservation::create([
                'id' => $id,
                'stock_item_id' => $item->id,
                'quantity' => $quantity,
                'kind' => $kind,
                'status' => StockReservationStatus::Active,
                'reference' => $reference,
                'expires_at' => $this->windows->expiryFor($kind),
                'business_account_id' => $account?->id,
                'stock_allocation_id' => $lockedAllocation?->id,
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

                // Re-read under the lock: an override may have moved the expiry
                // after the sweep chose this reservation (P3-26).
                if ($to === StockReservationStatus::Expired && $locked->expires_at->isFuture()) {
                    throw InventoryRefused::notYetExpired();
                }

                if ($locked->isSupplierSourced()) {
                    // A Supplier offer's units: same lifecycle, its own stock.
                    $this->supplierStock->end($locked, $to, $reason);
                } else {
                    $this->endCentral($locked, $to, $reason, $overriddenBy);
                }

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
     * Give a central reservation's units back, or on, through the stock ledger.
     */
    protected function endCentral(StockReservation $locked, StockReservationStatus $to, ?string $reason, ?int $overriddenBy): void
    {
        // Units drawn from an account's allocation go back to it, not to
        // everyone — unless the order went ahead (P3-30).
        $returnsToAllocation = $to !== StockReservationStatus::Committed && $locked->stock_allocation_id !== null;

        /** @var StockItem $item */
        $item = StockItem::query()->lockForUpdate()->findOrFail($locked->stock_item_id);

        $allocation = $returnsToAllocation
            ? StockAllocation::query()->lockForUpdate()->findOrFail($locked->stock_allocation_id)
            : null;

        $this->ledger->move(
            $item,
            StockBucket::Reserved,
            match (true) {
                $to === StockReservationStatus::Committed => StockBucket::Processing,
                $returnsToAllocation => StockBucket::Allocated,
                default => StockBucket::Available,
            },
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

        $allocation?->forceFill(['quantity' => $allocation->quantity + $locked->quantity])->save();
    }

    /**
     * A retried Supplier reserve must be the same request too.
     *
     * @throws InventoryRefused
     */
    protected function sameSupplierReservation(
        StockReservation $existing,
        SupplierOffer $offer,
        int $quantity,
        ReservationKind $kind,
        ?BusinessAccount $account = null,
    ): StockReservation {
        $stock = $existing->supplierStock;

        if ($stock === null
            || $stock->supplier_offer_id !== $offer->id
            || $existing->quantity !== $quantity
            || $existing->kind !== $kind
            || $existing->business_account_id !== $account?->id) {
            throw InventoryRefused::referenceInUse($existing->reference);
        }

        return $existing;
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
        ?BusinessAccount $account = null,
    ): StockReservation {
        $item = $existing->item;

        // A central retry that finds a Supplier reservation under its reference
        // is a reference reused for something else.
        if ($item === null) {
            throw InventoryRefused::referenceInUse($existing->reference);
        }

        if ($item->product_id !== $product->id
            || $item->product_variant_id !== $variant?->id
            || $existing->quantity !== $quantity
            || $existing->kind !== $kind
            || $existing->business_account_id !== $account?->id) {
            throw InventoryRefused::referenceInUse($existing->reference);
        }

        return $existing;
    }

    protected function find(string $reference): ?StockReservation
    {
        return StockReservation::query()->with(['item', 'supplierStock'])->where('reference', $reference)->first();
    }
}
