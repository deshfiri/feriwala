<?php

namespace App\Domain\Order\Actions;

use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\WholesaleCancellation;
use App\Domain\Order\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Log\LogManager;
use Throwable;

/**
 * The scheduled pass over ERP wholesale orders still waiting for payment
 * (§14, §19.1, P4-10).
 *
 * Three kinds of order, each answered by the action that owns it:
 *
 *   1. **Paid, but still waiting** — a settlement that recorded the money and
 *      stopped before the order. Confirmed now: stock committed and paid, or held
 *      for review.
 *   2. **Payment closed** — failed, cancelled, or already handed to
 *      reconciliation. Cancelled, and its stock given back.
 *   3. **Window closed** — an open payment past its deadline, including one the
 *      gateway reported as pending: the stock cannot stay off sale for money that
 *      may never come. Cancelled, and its stock given back; money that arrives
 *      later is reconciled, never applied to the cancelled order.
 *
 * **Every minute**, like the reservation sweep it sits beside, and idempotent: each
 * order is re-read under its payment's settlement lock and its own row lock, so a
 * second pass or a racing callback finds the work done. One order failing is
 * logged and the pass moves on.
 */
class ExpireUnpaidWholesaleOrders
{
    public function __construct(
        protected ConfirmWholesaleOrderPayment $confirm,
        protected CancelUnpaidWholesaleOrder $cancel,
        protected LogManager $log,
    ) {}

    /**
     * @return array{confirmed: int, cancelled: int, expired: int}
     */
    public function handle(): array
    {
        $counts = ['confirmed' => 0, 'cancelled' => 0, 'expired' => 0];

        $this->each(
            $this->waiting()->whereHas('payment', fn (Builder $query) => $query->whereIn('status', [PaymentStatus::Paid, PaymentStatus::PartiallyRefunded])),
            function (Order $order) use (&$counts) {
                if ($order->payment !== null && $this->confirm->handle($order->payment)?->status !== OrderStatus::PaymentPending) {
                    $counts['confirmed']++;
                }
            },
        );

        $this->each(
            $this->waiting()->whereHas('payment', fn (Builder $query) => $query->whereIn('status', [
                PaymentStatus::Failed,
                PaymentStatus::Cancelled,
                PaymentStatus::ReconciliationRequired,
            ])),
            function (Order $order) use (&$counts) {
                $why = $order->payment?->status === PaymentStatus::Failed
                    ? WholesaleCancellation::PaymentFailed
                    : WholesaleCancellation::PaymentCancelled;

                if ($this->cancel->handle($order, $why)) {
                    $counts['cancelled']++;
                }
            },
        );

        $this->each(
            $this->waiting()->whereHas('payment', fn (Builder $query) => $query
                ->whereIn('status', PaymentStatus::open())
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', CarbonImmutable::now())),
            function (Order $order) use (&$counts) {
                if ($this->cancel->handle($order, WholesaleCancellation::PaymentExpired)) {
                    $counts['expired']++;
                }
            },
        );

        return $counts;
    }

    /**
     * @return Builder<Order>
     */
    protected function waiting(): Builder
    {
        return Order::query()
            ->where('source', OrderSource::ErpWholesale)
            ->where('status', OrderStatus::PaymentPending)
            ->with('payment');
    }

    /**
     * @param  Builder<Order>  $query
     * @param  callable(Order): void  $callback
     */
    protected function each(Builder $query, callable $callback): void
    {
        $query->chunkById(100, function ($orders) use ($callback) {
            foreach ($orders as $order) {
                try {
                    $callback($order);
                } catch (Throwable $throwable) {
                    $this->log->channel('payment')->error('Could not settle an unpaid wholesale order', [
                        'order' => $order->reference,
                        'payment' => $order->payment instanceof Payment ? $order->payment->reference : null,
                        'error' => $throwable->getMessage(),
                    ]);
                }
            }
        });
    }
}
