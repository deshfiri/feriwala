<?php

namespace App\Domain\Order\Actions;

use App\Domain\Billing\Actions\IssueInvoice;
use App\Domain\Billing\Actions\RecordPaymentLog;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\PaymentLog;
use App\Domain\Inventory\Enums\StockReservationStatus;
use App\Domain\Inventory\Exceptions\InventoryRefused;
use App\Domain\Inventory\StockEnforcement;
use App\Domain\Inventory\StockReservations;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Exceptions\OrderStockUnconfirmable;
use App\Domain\Order\Models\Order;
use App\Domain\Supplier\Actions\HoldSupplierPayable;
use App\Domain\Supplier\Actions\QualifySupplierPayablePayment;
use App\Domain\Wholesale\Actions\CloseOrderedCart;
use App\Notifications\Orders\WebsiteOrderPaid;
use App\Support\Concurrency\Exceptions\LockTimeout;
use App\Support\StatusHistory\StatusChange;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Log\LogManager;
use Throwable;

/**
 * What a settled order payment does to its order — ERP wholesale or website
 * (§14, §17, §19.1, P4-9–P4-11, P5-23).
 *
 * Run by settlement once the payment is paid, and again by the sweep for any
 * order a settlement left waiting. In one transaction, with the order and then
 * its payment locked:
 *
 *   - every line's reservation must be the one placed for it, still holding its
 *     units — and each is committed to the order;
 *   - the order moves along the transition map to `paid`, recorded with a note
 *     for the buyer — and, for a website order, announced to its storefront;
 *   - the invoice is issued from the payment's own components (P4-11);
 *   - a wholesale order's cart is emptied.
 *
 * A website order's owner is told a paid order is waiting for them.
 *
 * **The same order, once.** An order no longer waiting for payment is answered
 * as it stands, so a repeated callback commits nothing, issues nothing and moves
 * nothing again.
 *
 * **Held, not paid, when the stock is not there.** If a reservation is missing,
 * does not match its line, has already ended or cannot be committed, everything
 * above rolls back and the order moves to `on_hold` instead, with the reason for
 * staff and a critical alert: the money is real and the stock has to be found or
 * the payment refunded by a person. No invoice is issued for a held order.
 */
class ConfirmOrderPayment
{
    public function __construct(
        protected StockReservations $reservations,
        protected IssueInvoice $invoices,
        protected CloseOrderedCart $carts,
        protected RecordPaymentLog $logs,
        protected QualifySupplierPayablePayment $supplierPayment,
        protected HoldSupplierPayable $supplierHold,
        protected DatabaseManager $database,
        protected LogManager $log,
        protected StockEnforcement $enforcement,
    ) {}

    public function handle(Payment $payment): ?Order
    {
        $order = Order::query()->where('payment_id', $payment->id)->first();

        if ($order === null) {
            $this->log->channel('payment')->critical('Settled an order payment with no order', [
                'payment' => $payment->reference,
                'business_account' => $payment->business_account_id,
                'amount' => $payment->amount->toDecimal(),
            ]);

            $this->record($payment, 'order_missing');

            return null;
        }

        $wasWaiting = $order->status === OrderStatus::PaymentPending;

        try {
            $this->database->transaction(fn () => $this->confirm($order, $payment));
        } catch (OrderStockUnconfirmable|InventoryRefused|LockTimeout $problem) {
            $this->hold($order, $payment, $problem->getMessage());
        }

        $order->refresh();

        if ($wasWaiting && $order->source === OrderSource::Website && $order->status !== OrderStatus::PaymentPending) {
            $this->tellOwner($order);
        }

        return $order;
    }

    /**
     * A customer paid on the owner's shop: tell them there is an order to look
     * at — paid and on its way, or held for Feriwala to resolve.
     *
     * Never allowed to fail the confirmation that has already happened.
     */
    protected function tellOwner(Order $order): void
    {
        try {
            $order->businessAccount()->with('owner')->first()?->owner?->notify(new WebsiteOrderPaid($order));
        } catch (Throwable $throwable) {
            $this->log->channel('payment')->error('Could not tell a website owner about a paid order', [
                'order' => $order->reference,
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    protected function confirm(Order $order, Payment $payment): void
    {
        /** @var Order $locked */
        $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

        if ($locked->status !== OrderStatus::PaymentPending) {
            return;
        }

        /** @var Payment $settled */
        $settled = Payment::query()->lockForUpdate()->findOrFail($payment->id);

        if (! $settled->status->isSettled()) {
            return;
        }

        $items = $locked->items()->with('stockReservation')->get();

        if ($items->isEmpty()) {
            throw new OrderStockUnconfirmable('The order has no lines to commit stock for.');
        }

        $enforced = $this->enforcement->enforced();

        foreach ($items as $item) {
            $reservation = $item->stockReservation;

            // With stock not blocking orders, a line that was never held, or
            // whose hold has lapsed, is simply not committed: the payment stands.
            if (! $enforced && ($reservation === null || $reservation->status !== StockReservationStatus::Active)) {
                continue;
            }

            if ($reservation === null) {
                throw new OrderStockUnconfirmable(sprintf('Line %d has no stock reservation.', $item->line_number));
            }

            if ($reservation->reference !== $locked->reference.'-L'.$item->line_number
                || $reservation->quantity !== $item->quantity) {
                throw new OrderStockUnconfirmable(sprintf('Line %d\'s reservation %s does not match the line.', $item->line_number, $reservation->reference));
            }

            if ($reservation->status !== StockReservationStatus::Active) {
                throw new OrderStockUnconfirmable(sprintf('Line %d\'s reservation %s is already %s.', $item->line_number, $reservation->reference, $reservation->status->value));
            }
        }

        foreach ($items as $item) {
            $reservation = $item->stockReservation;

            if ($reservation !== null && ($enforced || $reservation->status === StockReservationStatus::Active)) {
                $this->reservations->commit($reservation);
            }
        }

        $locked->forceFill(['paid_at' => $settled->completed_at ?? CarbonImmutable::now()]);

        $locked->moveTo(
            OrderStatus::Paid,
            StatusChange::bySystem('The payment settled and the stock was committed.', 'orders.notes.paid'),
            OrderStatusChangeSource::PaymentGateway,
        );

        // Only now: an invoice is a record of a sale this order has become (P4-11).
        $this->invoices->handle($settled);

        $this->carts->afterPayment($locked);

        // Records the fact only; a Supplier payable needs delivery too before
        // it is eligible for settlement (D25, P13-22).
        $this->supplierPayment->handle($locked, $settled->completed_at);

        $order->setRawAttributes($locked->getAttributes(), sync: true);
    }

    /**
     * Record a settled order whose stock could not be committed, for a person to resolve.
     */
    protected function hold(Order $order, Payment $payment, string $problem): void
    {
        $reason = 'The payment settled, but the stock held for this order could not be committed.';

        $held = $this->database->transaction(function () use ($order, $payment, $problem, $reason) {
            /** @var Order $locked */
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($locked->status !== OrderStatus::PaymentPending) {
                return false;
            }

            /** @var Payment $settled */
            $settled = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if (! $settled->status->isSettled()) {
                return false;
            }

            $locked->forceFill([
                'held_at' => CarbonImmutable::now(),
                'hold_reason' => $reason.' '.$problem,
            ]);

            $locked->moveTo(
                OrderStatus::OnHold,
                new StatusChange(reason: $reason, internalNote: $problem, publicNote: 'orders.notes.held'),
                OrderStatusChangeSource::PaymentGateway,
            );

            $this->carts->afterPayment($locked);

            // The reservation the payable's line relied on is missing or
            // invalid: the payable is frozen with the order, consistent with
            // the order's own on_hold path (D25, P13-22).
            $this->supplierHold->handle($locked, $reason.' '.$problem);

            return true;
        });

        if (! $held) {
            return;
        }

        $this->log->channel('payment')->critical('Settled order held: stock could not be committed', [
            'order' => $order->reference,
            'payment' => $payment->reference,
            'business_account' => $payment->business_account_id,
            'amount' => $payment->amount->toDecimal(),
            'problem' => $problem,
        ]);

        $this->record($payment, 'order_held', ['order' => $order->reference, 'problem' => $problem]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function record(Payment $payment, string $outcome, array $context = []): void
    {
        $this->logs->handle(
            gateway: is_string($payment->gateway) && $payment->gateway !== '' ? $payment->gateway : 'unknown',
            direction: PaymentLog::OUTBOUND,
            event: 'settle',
            payment: $payment,
            amount: $payment->amount,
            outcome: $outcome,
            context: $context,
        );
    }
}
