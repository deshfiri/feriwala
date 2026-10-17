<?php

namespace App\Domain\Order\Actions;

use App\Domain\Inventory\Enums\StockReservationStatus;
use App\Domain\Inventory\StockReservations;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Enums\StoreOrderAction;
use App\Domain\Order\Exceptions\StoreOrderRefused;
use App\Domain\Order\Models\Order;
use App\Models\User;
use App\Support\StatusHistory\StatusChange;
use Illuminate\Database\DatabaseManager;

/**
 * Move a Store Order one step along its fixed sequence (D34).
 *
 * Pending → Confirmed → Processing → Packing → Packed → Dispatched, each a named
 * action ({@see StoreOrderAction}) that is legal from exactly one place. The
 * order is re-read under its row lock, so two requests at once cannot both move
 * it; the second finds the order already where the action takes it and is
 * answered with the order as it stands — no second history row, no second stock
 * movement, no second announcement to the storefront.
 *
 * **Stock.** Until dispatch the units stay reserved: confirming, processing,
 * packing and packing-complete all leave the reservation held. **Dispatch is the
 * one boundary that consumes it**, once, in the same transaction as the status
 * move — so a stock failure rolls the dispatch back with it.
 */
class AdvanceStoreOrder
{
    public function __construct(
        protected StockReservations $reservations,
        protected DatabaseManager $database,
    ) {}

    /**
     * @return array{0: Order, 1: bool} the order, and whether this call moved it
     *
     * @throws StoreOrderRefused
     */
    public function handle(Order $order, StoreOrderAction $action, User $actor, ?string $note = null): array
    {
        return $this->database->transaction(function () use ($order, $action, $actor, $note) {
            /** @var Order $locked */
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($locked->source !== OrderSource::Website) {
                throw StoreOrderRefused::notStoreOrder();
            }

            // The same request again, or a racing one that lost: already there.
            if ($locked->status === $action->to()) {
                return [$locked, false];
            }

            if (! in_array($locked->status, $action->allowedFrom(), true)) {
                throw StoreOrderRefused::wrongStep($action->label(), $locked->status->value);
            }

            if ($action === StoreOrderAction::Dispatch) {
                $this->consumeStock($locked);
            }

            if ($action === StoreOrderAction::Confirm) {
                $this->keepStockHeld($locked);
            }

            $locked->moveTo(
                $action->to(),
                new StatusChange(
                    actorId: $actor->id,
                    reason: $note !== null && trim($note) !== '' ? trim($note) : $action->label(),
                    publicNote: 'store_orders.notes.'.$action->value,
                ),
                OrderStatusChangeSource::Staff,
            );

            return [$locked, true];
        });
    }

    /**
     * Dispatch: commit what the order has been holding, exactly once.
     *
     * A reservation already committed is skipped — that is how a repeat is safe —
     * and one that ended any other way means the stock is gone, so the dispatch
     * is refused rather than shipping goods nobody holds.
     */
    protected function consumeStock(Order $order): void
    {
        foreach ($order->items()->with('stockReservation')->get() as $item) {
            $reservation = $item->stockReservation;

            if ($reservation === null || ! $reservation->isPartnerSourced()) {
                continue;
            }

            if ($reservation->status === StockReservationStatus::Committed) {
                continue;
            }

            if ($reservation->status !== StockReservationStatus::Active) {
                throw StoreOrderRefused::stockNotHeld($item->sku);
            }

            $this->reservations->commit($reservation);
        }
    }

    /**
     * A staff confirmation proves the order is real, so its short window ends.
     */
    protected function keepStockHeld(Order $order): void
    {
        foreach ($order->items()->with('stockReservation')->get() as $item) {
            if ($item->stockReservation?->isPartnerSourced() === true) {
                $this->reservations->holdUntilDispatch($item->stockReservation);
            }
        }
    }
}
