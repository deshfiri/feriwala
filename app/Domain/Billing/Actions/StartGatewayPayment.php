<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\PaymentLog;
use App\Integrations\Payment\Data\PaymentIntent;
use App\Integrations\Payment\Exceptions\GatewayUnavailable;
use App\Integrations\Payment\GatewayNavigation;
use App\Integrations\Payment\Gateways\GatewayCredentials;
use App\Integrations\Payment\PaymentGatewayManager;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Opens a gateway session for a payment that has been recorded, and returns
 * the address the payer is to be sent to (§26.4, D12).
 *
 * The same steps the web checkout, renewal and package-change controllers take
 * after recording the payment, for a client that cannot be redirected by the
 * server: it is handed the address instead. The payment stays a draft when the
 * gateway cannot be reached, so the quote is not lost and another gateway can
 * be tried.
 */
class StartGatewayPayment
{
    public function __construct(
        protected PaymentGatewayManager $gateways,
        protected RecordPaymentLog $logs,
    ) {}

    /**
     * @param  bool  $separateReturns  one return address per outcome, as an activation does;
     *                                 renewals and package changes send every outcome to the one
     * @return string the gateway's own page
     *
     * @throws ValidationException when the gateway is unavailable
     */
    public function handle(Payment $payment, string $gateway, Request $request, bool $separateReturns = true): string
    {
        $driver = $this->gateways->driver($gateway);

        $payment->forceFill([
            'gateway' => $gateway,
            // Stamped now and never recalculated (§26.4).
            'gateway_mode' => $driver->isSandbox()
                ? GatewayCredentials::SANDBOX
                : GatewayCredentials::LIVE,
        ])->save();

        try {
            $redirect = $driver->initiate(
                PaymentIntent::forPayment(
                    $payment,
                    successUrl: route('checkout.return'),
                    failUrl: route($separateReturns ? 'checkout.failed' : 'checkout.return'),
                    cancelUrl: route($separateReturns ? 'checkout.cancelled' : 'checkout.return'),
                    ipnUrl: route('webhooks.payment', $gateway),
                ),
            );

            GatewayNavigation::ensureOpenable($redirect->url, $gateway);
        } catch (GatewayUnavailable $exception) {
            $this->logs->handle(
                gateway: $gateway,
                direction: PaymentLog::OUTBOUND,
                event: 'initiate',
                payment: $payment,
                amount: $payment->amount,
                outcome: 'unavailable',
                context: ['error' => $exception->getMessage()],
                request: $request,
            );

            throw ValidationException::withMessages(['gateway' => $exception->getMessage()]);
        }

        $this->logs->handle(
            gateway: $gateway,
            direction: PaymentLog::OUTBOUND,
            event: 'initiate',
            payment: $payment,
            gatewayReference: $redirect->gatewayReference,
            amount: $payment->amount,
            outcome: 'session_created',
            request: $request,
        );

        if ($payment->canTransitionTo(PaymentStatus::Initiated)) {
            $payment->transitionTo(PaymentStatus::Initiated);
            $payment->forceFill(['initiated_at' => now()])->save();
        }

        return $redirect->url;
    }
}
