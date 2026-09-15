<?php

namespace App\Http\Controllers\Erp;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Billing\Actions\RecordPaymentLog;
use App\Domain\Billing\Actions\SettlePayment;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\PaymentLog;
use App\Domain\Order\Actions\CancelUnpaidWholesaleOrder;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\WholesaleCancellation;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Queries\WholesaleOrderTracking;
use App\Http\Controllers\Controller;
use App\Integrations\Payment\Data\GatewayResult;
use App\Integrations\Payment\Exceptions\GatewayUnavailable;
use App\Integrations\Payment\PaymentGatewayManager;
use App\Support\Concurrency\Exceptions\LockTimeout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Log\LogManager;
use Inertia\Inertia;
use Throwable;

/**
 * Where the gateway sends a person back to after paying for a wholesale order
 * (§14, §26.4, P4-9).
 *
 * The same rules as the activation checkout's return, with the order named in
 * the address: **scoped to the signed-in person's own account**, so another
 * account's order is a 404, and **deciding nothing on the browser's word**.
 *
 *   - **Success** asks the gateway, through the one settlement path, and then
 *     reads the order as it now stands: paid, held for review, under
 *     reconciliation, or still being confirmed by the IPN.
 *   - **Failed** and **cancelled** close only a payment nobody paid for — the
 *     order is cancelled and its stock given back. A settled payment is left
 *     alone, and a gateway that was wrong is corrected by the IPN into
 *     reconciliation, never into a paid order.
 *
 * Every screen lands on the order, so the person always sees where it stands.
 */
class WholesaleOrderPaymentReturnController extends Controller
{
    use ResolvesBusinessAccount;

    public function __construct(
        protected WholesaleOrderTracking $tracking,
        protected RecordPaymentLog $logs,
        protected PaymentGatewayManager $gateways,
    ) {}

    public function success(Request $request, string $order, SettlePayment $settle, LogManager $log): RedirectResponse
    {
        $record = $this->orderFor($request, $order);
        $payment = $record->payment;

        $this->record($request, 'return', $record, $payment);

        if ($payment !== null && ! $payment->isSettled()) {
            $callback = $this->callbackFor($payment, $request);

            if ($callback === null || $callback->gatewayReference === null) {
                return $this->toOrder($record, 'info', 'orders.flash.checking');
            }

            try {
                $settle->handle($payment, $callback->gatewayReference);
            } catch (GatewayUnavailable|LockTimeout $exception) {
                // Not a failure — we could not ask yet. The IPN settles it.
                $log->channel('payment')->warning('Could not verify a wholesale order payment on return', [
                    'order' => $record->reference,
                    'payment' => $payment->reference,
                    'error' => $exception->getMessage(),
                ]);

                return $this->toOrder($record, 'info', 'orders.flash.checking');
            }
        }

        $record->refresh();
        $status = $payment?->refresh()->status;

        return match (true) {
            $record->status === OrderStatus::Paid => $this->toOrder($record, 'success', 'orders.flash.paid'),
            $record->status === OrderStatus::OnHold => $this->toOrder($record, 'warning', 'orders.flash.held'),
            $status?->needsReconciliation() ?? false => $this->toOrder($record, 'info', 'orders.flash.reconciling'),
            $status?->isSettled() ?? false => $this->toOrder($record, 'info', 'orders.flash.checking'),
            $record->status === OrderStatus::PaymentPending => $this->toOrder($record, 'info', 'orders.flash.checking'),
            default => $this->toOrder($record, 'error', 'orders.flash.payment_failed'),
        };
    }

    public function cancelled(Request $request, string $order, CancelUnpaidWholesaleOrder $cancel): RedirectResponse
    {
        return $this->abandon($request, $order, $cancel, WholesaleCancellation::PaymentCancelled, 'cancel', 'orders.flash.payment_cancelled');
    }

    public function failed(Request $request, string $order, CancelUnpaidWholesaleOrder $cancel): RedirectResponse
    {
        return $this->abandon($request, $order, $cancel, WholesaleCancellation::PaymentFailed, 'fail', 'orders.flash.payment_failed');
    }

    protected function abandon(
        Request $request,
        string $order,
        CancelUnpaidWholesaleOrder $cancel,
        WholesaleCancellation $why,
        string $event,
        string $message,
    ): RedirectResponse {
        $record = $this->orderFor($request, $order);

        $this->record($request, $event, $record, $record->payment);

        try {
            $cancel->handle($record, $why);
        } catch (LockTimeout) {
            // Settlement has the payment. Whatever it decides is what the order shows.
        }

        $record->refresh();

        return $record->status === OrderStatus::Cancelled
            ? $this->toOrder($record, 'info', $message)
            : $this->toOrder($record, 'info', 'orders.flash.checking');
    }

    protected function toOrder(Order $order, string $type, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => __($message)]);

        return to_route('wholesale.orders.show', $order->public_id);
    }

    /**
     * Keep the redirect on the record (§42), as the activation return does.
     */
    protected function record(Request $request, string $event, Order $order, ?Payment $payment): void
    {
        $gateway = $payment?->gateway;
        $gateway = is_string($gateway) && $gateway !== '' ? $gateway : 'unknown';

        $result = $payment === null ? null : $this->callbackFor($payment, $request);

        $this->logs->handle(
            gateway: $gateway,
            direction: PaymentLog::INBOUND,
            event: $event,
            payment: $payment,
            reference: $result?->reference,
            gatewayReference: $result?->gatewayReference,
            outcome: $result?->outcome->value,
            context: [...$request->all(), 'order' => $order->reference],
            request: $request,
        );
    }

    protected function callbackFor(Payment $payment, Request $request): ?GatewayResult
    {
        try {
            return $this->gateways->driver((string) $payment->gateway)->handleCallback($request);
        } catch (Throwable) {
            return null;
        }
    }

    protected function orderFor(Request $request, string $order): Order
    {
        $record = $this->tracking->find($this->businessAccountFor($request), $order);

        abort_if($record === null, 404);

        return $record;
    }
}
