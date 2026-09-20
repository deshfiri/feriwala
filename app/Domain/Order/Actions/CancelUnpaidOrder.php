<?php

namespace App\Domain\Order\Actions;

use App\Domain\Billing\Actions\SettleCouponRedemption;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Inventory\Enums\StockReservationStatus;
use App\Domain\Inventory\Exceptions\InventoryRefused;
use App\Domain\Inventory\StockReservations;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\UnpaidOrderCancellation;
use App\Domain\Order\Exceptions\OrderRefused;
use App\Domain\Order\Models\Order;
use App\Domain\Wholesale\Actions\CloseOrderedCart;
use App\Models\User;
use App\Support\Concurrency\DistributedLock;
use App\Support\Concurrency\Exceptions\LockTimeout;
use App\Support\StatusHistory\StatusChange;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;

/**
 * Cancel an order nobody paid for — ERP wholesale or website — and give back
 * what it held (§14, §17, §19.1, P4-10, P5-23).
 *
 * Failed, cancelled and expired payments, and a person cancelling before paying —
 * the account, staff, or a website's customer through the storefront — all end
 * here. In one transaction, with the order and then its payment locked:
 *
 *   - an open payment is closed — failed or cancelled — through its status map;
 *   - every reservation still holding stock for the order is released, once: a
 *     reservation the sweep has already expired has given its units back and is
 *     left as it is;
 *   - the order moves to `cancelled` with its reason and a note for the buyer —
 *     and a website order's storefront is told;
 *   - a wholesale cart's confirmation for this order is withdrawn.
 *
 * Then the coupon's held use goes back.
 *
 * **Never an order whose money has arrived.** A settled payment is confirmed by
 * settlement, not cancelled here. Run under the same lock settlement takes for
 * the payment, so a cancellation and a gateway confirmation for one payment
 * queue rather than race: whichever is second finds the other's result. A payment
 * the gateway confirms after this has closed it goes to reconciliation, never to
 * the cancelled order.
 */
class CancelUnpaidOrder
{
    public function __construct(
        protected StockReservations $reservations,
        protected SettleCouponRedemption $coupons,
        protected CloseOrderedCart $carts,
        protected DatabaseManager $database,
        protected DistributedLock $lock,
    ) {}

    /**
     * @return bool whether this call cancelled the order
     *
     * @throws OrderRefused when a person asks to cancel an order that cannot be
     * @throws LockTimeout when settlement is holding the payment
     */
    public function handle(Order $order, UnpaidOrderCancellation $why, ?User $actor = null, ?string $internalNote = null): bool
    {
        if ($order->payment_id === null) {
            return false;
        }

        return $this->lock->run(
            key: 'payment:settle:'.$order->payment_id,
            callback: fn () => $this->cancel($order, $why, $actor, $internalNote),
            ttlSeconds: 30,
            waitSeconds: 10,
        );
    }

    /**
     * Cancel the order a payment was for, from inside settlement.
     *
     * The caller already holds the payment's settlement lock.
     */
    public function forPaymentWithinSettlement(Payment $payment, UnpaidOrderCancellation $why): bool
    {
        $order = Order::query()->where('payment_id', $payment->id)->first();

        return $order !== null && $this->cancel($order, $why, null);
    }

    protected function cancel(Order $order, UnpaidOrderCancellation $why, ?User $actor, ?string $internalNote = null): bool
    {
        $payment = null;

        $cancelled = $this->database->transaction(function () use ($order, $why, $actor, $internalNote, &$payment) {
            /** @var Order|null $locked */
            $locked = Order::query()->lockForUpdate()->find($order->id);

            // Waiting for a payment, or for the customer to confirm a
            // cash-on-delivery order: in both, nobody has paid (P6-10).
            if ($locked === null || ! in_array($locked->status, [OrderStatus::PaymentPending, OrderStatus::CustomerVerificationPending], true)) {
                return $this->refuse($why, OrderRefused::notAwaitingPayment());
            }

            /** @var Payment $payment */
            $payment = Payment::query()->lockForUpdate()->findOrFail($locked->payment_id);

            if ($payment->status->isSettled()) {
                return $this->refuse($why, OrderRefused::notAwaitingPayment());
            }

            if ($payment->status === PaymentStatus::Pending && $why->isByPerson()) {
                throw OrderRefused::paymentInFlight();
            }

            $open = in_array($payment->status, PaymentStatus::open(), true);

            // Re-read under the lock: an expiry is only an expiry while the window is shut.
            if (in_array($why, [UnpaidOrderCancellation::PaymentExpired, UnpaidOrderCancellation::ConfirmationExpired], true) && $open
                && ($payment->expires_at === null || $payment->expires_at->isFuture())) {
                return false;
            }

            if ($open) {
                $this->closePayment($payment, $why);
            }

            $this->releaseStock($locked, $why);

            $locked->forceFill([
                'cancelled_at' => CarbonImmutable::now(),
                'cancellation_reason' => $why->reason(),
            ]);

            $locked->moveTo(
                OrderStatus::Cancelled,
                new StatusChange(actorId: $actor?->id, reason: $why->reason(), internalNote: $internalNote, publicNote: $why->publicNote()),
                $why->source(),
            );

            $this->carts->afterCancellation($locked);

            $order->setRawAttributes($locked->getAttributes(), sync: true);

            return true;
        });

        if ($cancelled && $payment instanceof Payment) {
            // This payment's hold only. Another checkout's hold on the same coupon is not ours.
            $this->coupons->release($payment);
        }

        return (bool) $cancelled;
    }

    protected function closePayment(Payment $payment, UnpaidOrderCancellation $why): void
    {
        $to = $why->paymentStatus();

        $payment->transitionTo($to);

        $payment->forceFill($to === PaymentStatus::Failed
            ? ['failed_at' => CarbonImmutable::now(), 'failure_reason' => $why->reason()]
            : ['cancelled_at' => CarbonImmutable::now()])
            ->save();
    }

    /**
     * Give back the stock each line still holds.
     */
    protected function releaseStock(Order $order, UnpaidOrderCancellation $why): void
    {
        foreach ($order->items()->with('stockReservation')->get() as $item) {
            $reservation = $item->stockReservation;

            if ($reservation === null || $reservation->status !== StockReservationStatus::Active) {
                continue;
            }

            try {
                $this->reservations->release($reservation, $order->reference.': '.$why->reason());
            } catch (InventoryRefused) {
                // Ended another way under its own lock since it was read — the
                // expiry sweep got there first. Its units are already back.
            }
        }
    }

    /**
     * A person asking for the impossible is told; the gateway and the clock are not.
     */
    protected function refuse(UnpaidOrderCancellation $why, OrderRefused $refusal): bool
    {
        if ($why->isByPerson()) {
            throw $refusal;
        }

        return false;
    }
}
