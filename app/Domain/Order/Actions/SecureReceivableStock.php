<?php

namespace App\Domain\Order\Actions;

use App\Domain\Inventory\Enums\ReservationKind;
use App\Domain\Inventory\Enums\StockReservationStatus;
use App\Domain\Inventory\Exceptions\InventoryRefused;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Inventory\StockEnforcement;
use App\Domain\Inventory\StockReservations;
use App\Domain\Order\Exceptions\ReceivableStockUnavailable;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderHoldReservation;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\OrderReceivableCollection;
use App\Support\Concurrency\Exceptions\LockTimeout;
use Illuminate\Database\Eloquent\Collection;

/**
 * Makes sure every line of a Partner-gateway order has stock set aside before
 * Banij debits the Partner's Wallet for it (D36).
 *
 * The reservation placed with the order lasts fifteen minutes; collection can
 * come long after. Under the order's lock, for each line:
 *
 *   - the reservation still **active** is kept — the original, or the latest
 *     replacement made by an earlier attempt;
 *   - one that has **lapsed** (expired, released) is never revived, and the line
 *     cannot be repointed, so stock is set aside again under a new reference
 *     `<order>-L<line>-H<n>` and linked to the line in `order_hold_reservations`;
 *   - one that cannot be set aside again stops everything with
 *     {@see ReceivableStockUnavailable}, which rolls the whole attempt back, so a
 *     reservation made for an earlier line is not left holding stock for an order
 *     that cannot go ahead.
 *
 * Nothing is committed, extended or released here: the caller decides what the
 * secured stock is for once it knows whether the Wallet can pay. With stock not
 * blocking orders, a line with no active reservation is simply not held, exactly
 * as when the payment of an ordinary order is confirmed.
 */
class SecureReceivableStock
{
    public function __construct(
        protected StockReservations $reservations,
        protected StockEnforcement $enforcement,
    ) {}

    /**
     * The order must already be locked by the caller.
     *
     * @return list<StockReservation> the active reservation of each line, in line order
     *
     * @throws ReceivableStockUnavailable
     */
    public function handle(Order $order, OrderReceivableCollection $collection): array
    {
        $enforced = $this->enforcement->enforced();

        /** @var Collection<int, OrderItem> $items */
        $items = $order->items()->orderBy('line_number')->get();

        if ($items->isEmpty()) {
            throw ReceivableStockUnavailable::forLine(0, 'the order has no lines to hold stock for.');
        }

        $secured = [];

        foreach ($items as $item) {
            $current = $this->current($collection, $item);

            if ($current !== null && $current->status === StockReservationStatus::Active) {
                $this->mustBeTheLine($order, $item, $current);

                $secured[] = $current;

                continue;
            }

            if (! $enforced) {
                continue;
            }

            $secured[] = $this->setAsideAgain($order, $collection, $item);
        }

        return $secured;
    }

    /**
     * The reservation that stands for the line now, read under its lock.
     */
    protected function current(OrderReceivableCollection $collection, OrderItem $item): ?StockReservation
    {
        $latest = OrderHoldReservation::query()
            ->where('order_receivable_collection_id', $collection->id)
            ->where('order_item_id', $item->id)
            ->orderByDesc('sequence')
            ->first();

        $id = $latest !== null ? $latest->stock_reservation_id : $item->stock_reservation_id;

        return $id === null ? null : StockReservation::query()->lockForUpdate()->find($id);
    }

    /**
     * A reservation that is still active must be the one placed for this line.
     *
     * @throws ReceivableStockUnavailable
     */
    protected function mustBeTheLine(Order $order, OrderItem $item, StockReservation $reservation): void
    {
        $own = $order->reference.'-L'.$item->line_number;

        $isOriginal = $reservation->reference === $own;
        $isReplacement = str_starts_with($reservation->reference, $own.'-H');

        if ((! $isOriginal && ! $isReplacement) || $reservation->quantity !== $item->quantity) {
            throw ReceivableStockUnavailable::forLine($item->line_number, sprintf('reservation %s does not match the line.', $reservation->reference));
        }
    }

    /**
     * @throws ReceivableStockUnavailable
     */
    protected function setAsideAgain(Order $order, OrderReceivableCollection $collection, OrderItem $item): StockReservation
    {
        $sequence = OrderHoldReservation::query()
            ->where('order_receivable_collection_id', $collection->id)
            ->where('order_item_id', $item->id)
            ->count() + 1;

        $product = $item->product;

        if ($product === null) {
            throw ReceivableStockUnavailable::forLine($item->line_number, 'the product is no longer in the catalogue.');
        }

        try {
            $reservation = $this->reservations->reserve(
                $product,
                $item->variant,
                $item->quantity,
                ReservationKind::OnlinePayment,
                $order->reference.'-L'.$item->line_number.'-H'.$sequence,
                $order->businessAccount,
            );
        } catch (InventoryRefused|LockTimeout $problem) {
            throw ReceivableStockUnavailable::forLine($item->line_number, $problem->getMessage());
        }

        OrderHoldReservation::query()->create([
            'order_receivable_collection_id' => $collection->id,
            'order_item_id' => $item->id,
            'stock_reservation_id' => $reservation->id,
            'purpose' => OrderHoldReservation::RECOVERY,
            'sequence' => $sequence,
        ]);

        return $reservation;
    }
}
