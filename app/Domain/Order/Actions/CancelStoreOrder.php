<?php

namespace App\Domain\Order\Actions;

use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Inventory\Enums\StockReservationStatus;
use App\Domain\Inventory\Exceptions\InventoryRefused;
use App\Domain\Inventory\StockReservations;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Exceptions\StoreOrderRefused;
use App\Domain\Order\Models\Order;
use App\Models\User;
use App\Support\StatusHistory\StatusChange;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;

/**
 * Cancel a Store Order that has not left the warehouse (D34).
 *
 * Cancellation is an exception to the sequence, with its own rules:
 *
 * - **Before dispatch only.** A dispatched order is never cancelled here; it
 *   follows the return, failed-delivery and return-to-origin flows, and its stock
 *   comes back only through an accepted return.
 * - **A reason, always**, with who and when on the order's history.
 * - **Stock is released once**, from the reservation the order still holds. It is
 *   a stock movement, not a refund.
 * - **Money is its own lifecycle.** An unpaid payment attempt is closed; one that
 *   has already settled sends the order to Refund pending, where the existing
 *   refund process takes over — this never refunds anything itself.
 * - **A Partner may cancel only while the order is still Pending.** Once it is
 *   being processed, packed or packed-and-waiting, cancelling needs staff.
 */
class CancelStoreOrder
{
    /** @var list<OrderStatus> */
    private const PENDING = [OrderStatus::New, OrderStatus::PendingConfirmation, OrderStatus::CustomerVerificationPending, OrderStatus::PaymentPending];

    /** @var list<OrderStatus> */
    private const BEFORE_DISPATCH = [
        OrderStatus::Confirmed, OrderStatus::Paid, OrderStatus::Processing, OrderStatus::StockReserved,
        OrderStatus::ReadyForFulfillment, OrderStatus::Packing, OrderStatus::ReadyForPickup,
    ];

    public function __construct(
        protected StockReservations $reservations,
        protected DatabaseManager $database,
    ) {}

    /**
     * @return array{0: Order, 1: bool} the order, and whether this call cancelled it
     *
     * @throws StoreOrderRefused
     */
    public function handle(Order $order, User $actor, string $reason, bool $asPartner = false): array
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw StoreOrderRefused::reasonRequired();
        }

        return $this->database->transaction(function () use ($order, $actor, $reason, $asPartner) {
            /** @var Order $locked */
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($locked->source !== OrderSource::Website) {
                throw StoreOrderRefused::notStoreOrder();
            }

            // Already cancelled, or already on its way to a refund: the repeat is answered.
            if (in_array($locked->status, [OrderStatus::Cancelled, OrderStatus::RefundPending], true)) {
                return [$locked, false];
            }

            $pending = in_array($locked->status, self::PENDING, true);

            if (! $pending && ! in_array($locked->status, self::BEFORE_DISPATCH, true)) {
                throw StoreOrderRefused::cannotCancelNow();
            }

            if ($asPartner && ! $pending) {
                throw StoreOrderRefused::cannotCancelNow();
            }

            $payment = $locked->payment()->lockForUpdate()->first();
            $paid = $payment !== null && $payment->status->isSettled();

            $this->releaseStock($locked, $reason);

            if ($payment !== null && ! $paid && $payment->canTransitionTo(PaymentStatus::Cancelled)) {
                $payment->transitionTo(PaymentStatus::Cancelled);
                $payment->forceFill(['cancelled_at' => CarbonImmutable::now()])->save();
            }

            $locked->forceFill([
                'cancelled_at' => CarbonImmutable::now(),
                'cancellation_reason' => $reason,
            ]);

            $locked->moveTo(
                $paid ? OrderStatus::RefundPending : OrderStatus::Cancelled,
                new StatusChange(
                    actorId: $actor->id,
                    reason: $reason,
                    publicNote: $paid ? 'store_orders.notes.cancelled_refund' : 'store_orders.notes.cancelled',
                ),
                OrderStatusChangeSource::Staff,
            );

            return [$locked, true];
        });
    }

    /**
     * Give back what each line still holds — once. A reservation that has
     * already ended (released, expired or committed) is left exactly as it is.
     */
    protected function releaseStock(Order $order, string $reason): void
    {
        foreach ($order->items()->with('stockReservation')->get() as $item) {
            $reservation = $item->stockReservation;

            if ($reservation === null || $reservation->status !== StockReservationStatus::Active) {
                continue;
            }

            try {
                $this->reservations->release($reservation, $order->reference.': '.$reason);
            } catch (InventoryRefused) {
                // Ended another way under its own lock since it was read.
            }
        }
    }
}
