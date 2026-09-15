<?php

namespace App\Domain\Order\Actions;

use App\Domain\Billing\Actions\RecordPaymentLog;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\PaymentLog;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Exceptions\OrderRefused;
use App\Domain\Order\Models\Order;
use App\Integrations\Payment\Data\PaymentIntent;
use App\Integrations\Payment\Exceptions\GatewayUnavailable;
use App\Integrations\Payment\PaymentGatewayManager;
use App\Support\Concurrency\DistributedLock;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use LogicException;

/**
 * Open a gateway session for a wholesale order's payment (§14, §26.4, P4-9).
 *
 * For the order's own payment and nothing else: the amount, reference and
 * gateway were fixed when the order was placed. Allowed while the order waits for
 * payment, the payment has not been handed to the gateway for confirmation, and
 * its window is still open — so "continue to payment" after a closed tab starts
 * a fresh session for the same payment rather than a second payment.
 *
 * The gateway sends the person back to return addresses that name the order, and
 * notifies the same IPN endpoint every checkout uses.
 */
class InitiateWholesaleOrderPayment
{
    public function __construct(
        protected PaymentGatewayManager $gateways,
        protected RecordPaymentLog $logs,
        protected DistributedLock $lock,
    ) {}

    /**
     * @return string the gateway address to send the person to
     *
     * @throws OrderRefused
     * @throws GatewayUnavailable
     */
    public function handle(Order $order, ?Request $request = null): string
    {
        $paymentId = $order->payment_id ?? throw new LogicException('A wholesale order has a payment.');

        // The settlement lock: a session is never opened for a payment that is
        // being confirmed or cancelled at the same moment.
        return $this->lock->run(
            key: 'payment:settle:'.$paymentId,
            callback: fn () => $this->initiate($order->refresh(), $request),
            ttlSeconds: 30,
            waitSeconds: 10,
        );
    }

    protected function initiate(Order $order, ?Request $request): string
    {
        /** @var Payment $payment */
        $payment = Payment::query()->findOrFail($order->payment_id);

        if ($order->status !== OrderStatus::PaymentPending) {
            throw OrderRefused::notAwaitingPayment();
        }

        if ($payment->status === PaymentStatus::Pending) {
            throw OrderRefused::paymentInFlight();
        }

        if (! in_array($payment->status, [PaymentStatus::Draft, PaymentStatus::Initiated], true)) {
            throw OrderRefused::notAwaitingPayment();
        }

        if ($payment->expires_at === null || ! $payment->expires_at->isFuture()) {
            throw OrderRefused::paymentWindowClosed();
        }

        $gateway = (string) $payment->gateway;

        if (! in_array($gateway, $this->gateways->availableFor($payment->amount_minor->currency), true)) {
            throw OrderRefused::paymentMethodUnavailable();
        }

        try {
            $redirect = $this->gateways->driver($gateway)->initiate(
                PaymentIntent::forPayment(
                    $payment,
                    successUrl: route('wholesale.orders.payment.return', $order->public_id),
                    failUrl: route('wholesale.orders.payment.failed', $order->public_id),
                    cancelUrl: route('wholesale.orders.payment.cancelled', $order->public_id),
                    ipnUrl: route('webhooks.payment', $gateway),
                ),
            );
        } catch (GatewayUnavailable $exception) {
            $this->logs->handle(
                gateway: $gateway,
                direction: PaymentLog::OUTBOUND,
                event: 'initiate',
                payment: $payment,
                amount: $payment->amount_minor,
                outcome: 'unavailable',
                context: ['error' => $exception->getMessage(), 'order' => $order->reference],
                request: $request,
            );

            throw $exception;
        }

        $this->logs->handle(
            gateway: $gateway,
            direction: PaymentLog::OUTBOUND,
            event: 'initiate',
            payment: $payment,
            gatewayReference: $redirect->gatewayReference,
            amount: $payment->amount_minor,
            outcome: 'session_created',
            context: ['order' => $order->reference],
            request: $request,
        );

        if ($payment->canTransitionTo(PaymentStatus::Initiated)) {
            $payment->transitionTo(PaymentStatus::Initiated);
            $payment->forceFill(['initiated_at' => CarbonImmutable::now()])->save();
        }

        return $redirect->url;
    }
}
