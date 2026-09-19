<?php

namespace App\Domain\Order\Actions;

use App\Domain\Billing\Actions\RecordPaymentLog;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\PaymentLog;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Exceptions\OrderRefused;
use App\Domain\Order\Models\Order;
use App\Integrations\Payment\Data\PaymentIntent;
use App\Integrations\Payment\Exceptions\GatewayUnavailable;
use App\Integrations\Payment\PaymentGatewayManager;
use App\Support\Concurrency\DistributedLock;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use LogicException;

/**
 * Open a gateway session for an order's payment — ERP wholesale or website
 * (§14, §17, §26.4, P4-9, P5-23, D12).
 *
 * For the order's own payment and nothing else: the amount, reference and
 * gateway were fixed when the order was placed. Allowed while the order waits for
 * payment, the payment has not been handed to the gateway for confirmation, and
 * its window is still open — so "continue to payment" after a closed tab starts
 * a fresh session for the same payment rather than a second payment.
 *
 * The gateway sends the person back to return addresses that name the order, and
 * notifies the same IPN endpoint every checkout uses. A website order's customer
 * comes back to Feriwala first — Feriwala is merchant of record and owns the
 * gateway (D12) — and is sent on to their storefront from there.
 */
class InitiateOrderPayment
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
        $paymentId = $order->payment_id ?? throw new LogicException('An order paid through a gateway has a payment.');

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
                    successUrl: route($this->returnRoute($order, 'return'), $order->public_id),
                    failUrl: route($this->returnRoute($order, 'failed'), $order->public_id),
                    cancelUrl: route($this->returnRoute($order, 'cancelled'), $order->public_id),
                    ipnUrl: route('webhooks.payment', $gateway),
                ),
            );
        } catch (GatewayUnavailable|ConnectionException $exception) {
            /*
             * Refused or unreachable, it is the same answer to the buyer: the
             * order is placed and can be paid again while its window is open.
             * An unreachable gateway is not allowed to become a server error
             * on a page that has already taken the order.
             */
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

            throw $exception instanceof GatewayUnavailable
                ? $exception
                : GatewayUnavailable::forGateway($gateway, 'the gateway could not be reached');
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

    /**
     * Where the gateway sends the payer back: a wholesale buyer to their signed-in
     * order page, a website's customer to the address that forwards them to
     * their storefront.
     */
    protected function returnRoute(Order $order, string $outcome): string
    {
        return $order->source === OrderSource::Website
            ? 'website-orders.payment.'.$outcome
            : 'wholesale.orders.payment.'.$outcome;
    }
}
