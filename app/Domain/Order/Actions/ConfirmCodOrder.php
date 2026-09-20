<?php

namespace App\Domain\Order\Actions;

use App\Domain\Account\VerificationCodes;
use App\Domain\Inventory\Enums\StockReservationStatus;
use App\Domain\Inventory\Exceptions\InventoryRefused;
use App\Domain\Inventory\StockReservations;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Exceptions\WebsiteOrderRefused;
use App\Domain\Order\Models\Order;
use App\Support\Concurrency\DistributedLock;
use App\Support\Concurrency\Exceptions\LockTimeout;
use App\Support\StatusHistory\StatusChange;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;

/**
 * A customer confirming their cash-on-delivery order with the code they were
 * sent (contract §6.1.2, §6.2, P6-10).
 *
 * **Confirmed once, whatever arrives twice.** The code is one-time, but the
 * guarantee does not rest on that: the order is re-read under a row lock and
 * only a order still waiting for its customer is moved, so two requests
 * carrying the same correct code — or the same request retried — produce one
 * confirmation, one status entry, one webhook and one committed reservation.
 * The second is answered with the order as it now stands.
 *
 * Confirming commits the stock the order has been holding since it was placed;
 * it does **not** make the order paid. The money is collected on delivery and
 * settled through §28, which is a later phase — so no invoice is issued here.
 *
 * A code that is wrong, spent or never issued gets one answer, and a window
 * that has closed gets another: which it was must not tell somebody guessing
 * whether an order exists.
 */
class ConfirmCodOrder
{
    public function __construct(
        protected VerificationCodes $codes,
        protected SendCodConfirmationCode $sender,
        protected StockReservations $reservations,
        protected DatabaseManager $database,
        protected DistributedLock $lock,
    ) {}

    /**
     * @throws WebsiteOrderRefused
     */
    public function handle(Order $order, string $code): Order
    {
        try {
            return $this->lock->run(
                key: 'cod-confirm:'.$order->id,
                callback: fn () => $this->confirm($order, $code),
                ttlSeconds: 30,
                waitSeconds: 10,
            );
        } catch (LockTimeout) {
            throw WebsiteOrderRefused::busy();
        }
    }

    /**
     * @throws WebsiteOrderRefused
     */
    protected function confirm(Order $order, string $code): Order
    {
        /** @var Order $current */
        $current = Order::query()->lockForUpdate()->findOrFail($order->id);

        // Already confirmed by the request that got here first.
        if ($current->status === OrderStatus::Confirmed) {
            return $current;
        }

        if ($current->status !== OrderStatus::CustomerVerificationPending) {
            throw WebsiteOrderRefused::notAwaitingConfirmation();
        }

        if ($this->windowClosed($current)) {
            throw WebsiteOrderRefused::confirmationExpired();
        }

        if (! $this->codes->verify(SendCodConfirmationCode::PURPOSE, $this->sender->identifierFor($current), $code)) {
            throw WebsiteOrderRefused::confirmationRefused();
        }

        return $this->database->transaction(function () use ($current) {
            /** @var Order $locked */
            $locked = Order::query()->lockForUpdate()->findOrFail($current->id);

            if ($locked->status === OrderStatus::Confirmed) {
                return $locked;
            }

            $this->commitStock($locked);

            $locked->moveTo(
                OrderStatus::Confirmed,
                new StatusChange(
                    reason: 'The customer confirmed the order with the code they were sent.',
                    publicNote: 'orders.notes.cod_confirmed',
                ),
                OrderStatusChangeSource::Storefront,
            );

            $this->sender->forget($locked);

            return $locked;
        });
    }

    /**
     * Whether the time to confirm has passed.
     *
     * The stock's own deadline is the order's: a confirmation after it would
     * commit units the sweep has already given back.
     */
    protected function windowClosed(Order $order): bool
    {
        $deadline = $order->items()->with('stockReservation')->get()
            ->map(fn ($item) => $item->stockReservation?->expires_at)
            ->filter()
            ->min();

        return $deadline === null || $deadline->lessThanOrEqualTo(CarbonImmutable::now());
    }

    /**
     * Commit what the order has been holding (contract §6.1.2).
     *
     * Stock that is no longer held means the sweep reached it first; the
     * confirmation loses, and the order is left for the sweep to close.
     *
     * @throws WebsiteOrderRefused
     */
    protected function commitStock(Order $order): void
    {
        foreach ($order->items()->with('stockReservation')->get() as $item) {
            $reservation = $item->stockReservation;

            if ($reservation === null || $reservation->status !== StockReservationStatus::Active) {
                throw WebsiteOrderRefused::confirmationExpired();
            }

            try {
                $this->reservations->commit($reservation);
            } catch (InventoryRefused) {
                throw WebsiteOrderRefused::confirmationExpired();
            }
        }
    }
}
