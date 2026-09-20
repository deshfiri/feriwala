<?php

namespace App\Domain\Order\Actions;

use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\UnpaidOrderCancellation;
use App\Domain\Order\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Log\LogManager;
use Throwable;

/**
 * The scheduled pass over cash-on-delivery orders nobody confirmed
 * (contract §6.1.2, §18.4, P6-10).
 *
 * A confirmation window is also the stock's: past it, the order is cancelled
 * and its units go back, because stock held for an order nobody confirmed is
 * stock taken off sale for nothing.
 *
 * Idempotent, and safe beside itself and beside a confirmation arriving at the
 * same moment: each order is re-read under its payment's settlement lock and
 * its own row lock, so a second pass — or a confirmation that got there first —
 * finds the work done and changes nothing. One order failing is logged and the
 * pass moves on.
 */
class ExpireUnconfirmedCodOrders
{
    public function __construct(
        protected CancelUnpaidOrder $cancel,
        protected SendCodConfirmationCode $codes,
        protected LogManager $log,
    ) {}

    /**
     * @return array{expired: int}
     */
    public function handle(): array
    {
        $expired = 0;

        Order::query()
            ->where('source', OrderSource::Website)
            ->where('status', OrderStatus::CustomerVerificationPending)
            ->whereHas('payment', fn (Builder $query) => $query
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', CarbonImmutable::now()))
            ->with('payment')
            ->chunkById(100, function ($orders) use (&$expired) {
                foreach ($orders as $order) {
                    try {
                        if ($this->cancel->handle($order, UnpaidOrderCancellation::ConfirmationExpired)) {
                            // The code dies with the order it belonged to.
                            $this->codes->forget($order);
                            $expired++;
                        }
                    } catch (Throwable $throwable) {
                        $this->log->channel('payment')->error('Could not close an unconfirmed order', [
                            'order' => $order->reference,
                            'error' => $throwable->getMessage(),
                        ]);
                    }
                }
            });

        return ['expired' => $expired];
    }
}
