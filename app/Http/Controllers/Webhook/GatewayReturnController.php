<?php

namespace App\Http\Controllers\Webhook;

use App\Domain\Billing\Actions\RecordPaymentLog;
use App\Domain\Billing\Actions\VerifyGatewayReturn;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\PaymentLog;
use App\Domain\Billing\Queries\ResolveNotifiedPayment;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Models\Order;
use App\Http\Controllers\Controller;
use App\Integrations\Payment\Data\GatewayCapability;
use App\Integrations\Payment\Data\GatewayResult;
use App\Integrations\Payment\PaymentGatewayManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * A gateway posting the payer's browser back to us (§26.4, §36).
 *
 * SSLCommerz and aamarPay return the person by a form POST from their own site.
 * That request carries no session cookie (the cookie is `SameSite=Lax`) and no
 * CSRF token, so it cannot reach the signed-in return pages. It lands here
 * instead — **outside the web middleware entirely**: no session is started, no
 * cookie is written (an empty session cookie in this response would sign the
 * person out), and no CSRF check runs. Only these exact POST routes are like
 * this; every page and every action a person takes stays inside the session and
 * behind the token.
 *
 * What it does is deliberately little:
 *
 *   - works out which payment the gateway named, through each driver's own
 *     reading of the request, as the IPN does;
 *   - writes the return to the payment log;
 *   - asks for verification only when {@see VerifyGatewayReturn} allows it —
 *     a valid provider signature, or an identifier we already hold;
 *   - and sends the browser on, with **one identical 303**, to the signed-in
 *     page for the same address, which reads the payment as it now stands.
 *
 * Nothing here settles, closes or cancels a payment, and the reply says nothing
 * about what was found.
 */
class GatewayReturnController extends Controller
{
    public function __construct(
        protected PaymentGatewayManager $gateways,
        protected ResolveNotifiedPayment $payments,
        protected VerifyGatewayReturn $verify,
        protected RecordPaymentLog $logs,
    ) {}

    public function checkoutReturn(Request $request): RedirectResponse
    {
        return $this->receive($request, 'return', route('checkout.return'));
    }

    public function checkoutCancelled(Request $request): RedirectResponse
    {
        return $this->receive($request, 'cancel', route('checkout.cancelled'));
    }

    public function checkoutFailed(Request $request): RedirectResponse
    {
        return $this->receive($request, 'fail', route('checkout.failed'));
    }

    public function orderReturn(Request $request, string $order): RedirectResponse
    {
        return $this->receive($request, 'return', route('wholesale.orders.payment.return', $order), $order);
    }

    public function orderCancelled(Request $request, string $order): RedirectResponse
    {
        return $this->receive($request, 'cancel', route('wholesale.orders.payment.cancelled', $order), $order);
    }

    public function orderFailed(Request $request, string $order): RedirectResponse
    {
        return $this->receive($request, 'fail', route('wholesale.orders.payment.failed', $order), $order);
    }

    protected function receive(Request $request, string $event, string $landing, ?string $order = null): RedirectResponse
    {
        [$payment, $result] = $order === null
            ? $this->namedByAnyGateway($request)
            : $this->namedForOrder($request, $order);

        $gateway = $payment !== null && is_string($payment->gateway) && $payment->gateway !== ''
            ? $payment->gateway
            : 'unknown';

        $this->logs->handle(
            gateway: $gateway,
            direction: PaymentLog::INBOUND,
            event: $event,
            payment: $payment,
            reference: $result?->reference,
            gatewayReference: $result?->gatewayReference,
            outcome: $result?->outcome->value,
            context: [...$request->all(), 'channel' => 'browser_post'],
            request: $request,
        );

        if ($payment !== null && $result !== null) {
            $this->verify->handle($payment, $request, $result, $event);
        }

        return redirect()->to($landing, 303);
    }

    /**
     * The payment a checkout return names, asked of every gateway that can verify.
     *
     * The shared checkout addresses do not say which gateway sent the person, so
     * each driver reads the request its own way; the payment found must belong to
     * that gateway, as a notification's must. A wholesale order's payment has
     * return addresses of its own and is never matched here.
     *
     * @return array{0: Payment|null, 1: GatewayResult|null}
     */
    protected function namedByAnyGateway(Request $request): array
    {
        foreach ($this->gateways->implemented() as $name) {
            $driver = $this->gateways->driver($name);

            if (! $driver->supports(GatewayCapability::Verify)) {
                continue;
            }

            $result = $this->read($name, $request);
            $payment = $result === null ? null : $this->payments->handle($name, $result);

            if ($payment !== null && $payment->purpose !== PaymentPurpose::WholesaleOrder) {
                return [$payment, $result];
            }
        }

        return [null, null];
    }

    /**
     * The order's own payment, and only if the gateway named exactly that one.
     *
     * @return array{0: Payment|null, 1: GatewayResult|null}
     */
    protected function namedForOrder(Request $request, string $order): array
    {
        $payment = Order::query()
            ->where('public_id', $order)
            ->where('source', OrderSource::ErpWholesale)
            ->first()
            ?->payment;

        if ($payment === null || ! is_string($payment->gateway) || $payment->gateway === '') {
            return [null, null];
        }

        $result = $this->read($payment->gateway, $request);

        if ($result === null || $this->payments->handle($payment->gateway, $result)?->id !== $payment->id) {
            return [null, $result];
        }

        return [$payment, $result];
    }

    protected function read(string $gateway, Request $request): ?GatewayResult
    {
        try {
            return $this->gateways->driver($gateway)->handleCallback($request);
        } catch (Throwable) {
            return null;
        }
    }
}
