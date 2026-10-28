<?php

namespace App\Domain\Order\Actions;

use App\Domain\Inventory\Enums\ReservationKind;
use App\Domain\Inventory\Exceptions\InventoryRefused;
use App\Domain\Inventory\StockReservations;
use App\Domain\Notification\Actions\AnnounceCaseEvent;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Exceptions\ReturnRefused;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\OrderReturn;
use App\Models\User;
use App\Support\Concurrency\Exceptions\LockTimeout;
use App\Support\StatusHistory\StatusChange;
use Illuminate\Database\DatabaseManager;

/**
 * Release an exchange's replacement to fulfilment (D35): reserve its stock and
 * confirm its order.
 *
 * **Only once the returned goods have been approved.** Reserving stock is an
 * inventory movement, and no inventory movement may be posted for a case before
 * its inspection is approved — so the replacement is not held at the request,
 * nor when the parcel arrives, but here, as the inspection approves the goods
 * and they are back on the shelf. A replacement of the very unit that came
 * back can be filled from it.
 *
 * The replacement is reserved through the same reservation service every order
 * uses, from the Partner's own units first (D34) and from central stock only for
 * a Non-Conditional account, one reservation per line under the line's own
 * reference, so retrying it holds nothing twice, and racing exchanges for the
 * last unit are made to take turns. It is then committed, because an exchange
 * order has no later store-workflow dispatch step to do it: the ordinary
 * fulfilment pipeline takes it from here, and delivery consumes the units like
 * any other.
 *
 * If the stock is not there the confirmation is refused with the reason and the
 * caller's transaction rolls back whole — the inspection is not approved over a
 * replacement nobody can send.
 *
 * Called inside the caller's transaction, with the case locked. Once: an order
 * already confirmed is returned as it stands.
 */
class ConfirmExchangeOrder
{
    public function __construct(
        protected StockReservations $reservations,
        protected DatabaseManager $database,
    ) {}

    /**
     * @throws ReturnRefused
     */
    public function handle(OrderReturn $case, ?User $actor = null): ?Order
    {
        if ($case->exchange_order_id === null) {
            return null;
        }

        /** @var Order $order */
        $order = Order::query()->lockForUpdate()->findOrFail($case->exchange_order_id);

        if ($order->status !== OrderStatus::PendingConfirmation) {
            return $order;
        }

        $account = $order->businessAccount;

        foreach ($order->items()->with(['product', 'variant'])->orderBy('line_number')->get() as $item) {
            $this->reserve($order, $item, $account->isNonConditional());
        }

        $order->moveTo(
            OrderStatus::Confirmed,
            new StatusChange(
                actorId: $actor?->id,
                reason: 'The returned goods were approved, so the replacement was released to fulfilment.',
                publicNote: 'orders.notes.exchange_confirmed',
            ),
            OrderStatusChangeSource::Staff,
        );

        // The replacement exists and is on its way into fulfilment: the Partner is told (D35).
        app(AnnounceCaseEvent::class)->exchangeOrder($case, 'exchange_order_created');

        return $order;
    }

    /**
     * Hold and commit one line's units.
     *
     * @throws ReturnRefused
     */
    protected function reserve(Order $order, OrderItem $item, bool $mayUseCentralStock): void
    {
        $reference = $order->reference.'-L'.$item->line_number;
        $account = $order->businessAccount;

        // A product removed since the request cannot be sent.
        if ($item->product === null) {
            throw ReturnRefused::replacementUnavailable($item->sku);
        }

        try {
            try {
                $reservation = $this->reservations->reserveFromPartner(
                    $account, $item->product, $item->variant, $item->quantity, ReservationKind::CashOnDelivery, $reference,
                );
            } catch (InventoryRefused $refused) {
                // A Non-Conditional account sells what it never bought: the line is
                // filled from central stock, exactly as its storefront orders are.
                if (! $mayUseCentralStock) {
                    throw $refused;
                }

                $reservation = $this->reservations->reserve(
                    $item->product, $item->variant, $item->quantity, ReservationKind::CashOnDelivery, $reference, $account,
                );
            }

            $this->reservations->commit($reservation);
            $item->forceFill(['stock_reservation_id' => $reservation->id])->save();
        } catch (InventoryRefused) {
            throw ReturnRefused::replacementOutOfStock($item->sku);
        } catch (LockTimeout) {
            throw ReturnRefused::busy();
        }
    }
}
