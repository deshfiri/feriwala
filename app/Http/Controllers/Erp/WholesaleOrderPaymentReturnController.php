<?php

namespace App\Http\Controllers\Erp;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Billing\Actions\RecordPaymentLog;
use App\Domain\Billing\Actions\SettlePayment;
use App\Domain\Billing\Actions\VerifyGatewayReturn;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\PaymentLog;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Queries\WholesaleOrderTracking;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Webhook\GatewayReturnController;
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
 *   - **Failed** and **cancelled** change nothing. The order keeps waiting — for
 *     the gateway to confirm what happened, for its payment window to close, or
 *     for the buyer to cancel it with the order page's own button — and the
 *     gateway is asked only when the return carries something it can check.
 *
 * These are the **GET** pages, inside the session; a gateway that posts the
 * person back reaches {@see GatewayReturnController} first. Every screen lands on
 * the order, so the person always sees where it stands.
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
                return $this->toOrder($record->refresh(), 'info', $this->standing($record));
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

        return $this->toOrder($record, $record->status === OrderStatus::Paid ? 'success' : 'info', $this->standing($record));
    }

    public function cancelled(Request $request, string $order, VerifyGatewayReturn $verify): RedirectResponse
    {
        return $this->acknowledge($request, $order, $verify, 'cancel');
    }

    public function failed(Request $request, string $order, VerifyGatewayReturn $verify): RedirectResponse
    {
        return $this->acknowledge($request, $order, $verify, 'fail');
    }

    protected function acknowledge(Request $request, string $order, VerifyGatewayReturn $verify, string $event): RedirectResponse
    {
        $record = $this->orderFor($request, $order);
        $payment = $record->payment;

        $this->record($request, $event, $record, $payment);

        $result = $payment === null ? null : $this->callbackFor($payment, $request);

        if ($payment !== null && $result !== null) {
            $verify->handle($payment, $request, $result, $event);
        }

        $record->refresh();

        return $this->toOrder(
            $record,
            'info',
            $record->status === OrderStatus::PaymentPending ? 'orders.flash.payment_not_completed' : $this->standing($record),
        );
    }

    /**
     * What to tell the person about the order as it stands now.
     */
    protected function standing(Order $order): string
    {
        $payment = $order->payment()->first();

        return match (true) {
            $order->status === OrderStatus::Paid => 'orders.flash.paid',
            $order->status === OrderStatus::OnHold => 'orders.flash.held',
            $payment?->status->needsReconciliation() ?? false => 'orders.flash.reconciling',
            $order->status === OrderStatus::PaymentPending => 'orders.flash.checking',
            default => 'orders.flash.payment_failed',
        };
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
