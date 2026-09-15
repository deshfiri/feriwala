<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Jobs\SettleGatewayNotification;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\PaymentLog;
use App\Integrations\Payment\Contracts\PaymentGateway;
use App\Integrations\Payment\Data\GatewayCapability;
use App\Integrations\Payment\Data\GatewayResult;
use App\Integrations\Payment\PaymentGatewayManager;
use Illuminate\Http\Request;
use Throwable;

/**
 * Whether a browser coming back from a gateway may ask for the payment to be
 * verified (§26.4, §36).
 *
 * **A browser return is never evidence.** It can only ask the gateway what
 * happened, through the same queued settlement every notification uses — and
 * only when the request carries something the payer's browser could not have
 * invented:
 *
 *   - the provider's **signature**, for a provider that signs its callbacks; or
 *   - for a provider that does not, a transaction identifier **we** already hold
 *     for this payment — our own reference, or the provider transaction recorded
 *     for it — so a forged callback cannot point verification at an identifier
 *     the gateway would answer "unknown" about and close a real payment with.
 *
 * Nothing here closes, cancels or settles anything. The gateway's answer does,
 * in {@see SettlePayment}.
 */
class VerifyGatewayReturn
{
    public function __construct(
        protected PaymentGatewayManager $gateways,
        protected RecordPaymentLog $logs,
    ) {}

    /**
     * @param  string  $event  what the return was: `return`, `cancel` or `fail`
     * @return bool whether verification was asked for
     */
    public function handle(Payment $payment, Request $request, GatewayResult $result, string $event): bool
    {
        $gateway = (string) $payment->gateway;
        $gatewayReference = $result->gatewayReference;

        if ($gateway === ''
            || $gatewayReference === null
            || $gatewayReference === ''
            || $payment->status->isSettled()
            || ! $this->gateways->isImplemented($gateway)) {
            return false;
        }

        $driver = $this->gateways->driver($gateway);

        if (! $driver->supports(GatewayCapability::Verify)) {
            return false;
        }

        $signs = $driver->supports(GatewayCapability::WebhookSignature);

        $trusted = $signs
            ? $this->signatureHolds($driver, $request)
            : in_array($gatewayReference, array_filter([$payment->reference, $payment->gateway_reference]), true);

        if (! $trusted) {
            $this->logs->handle(
                gateway: $gateway,
                direction: PaymentLog::INBOUND,
                event: $event,
                payment: $payment,
                gatewayReference: $gatewayReference,
                outcome: $signs ? 'refused_signature' : 'unverified_reference',
                request: $request,
            );

            return false;
        }

        SettleGatewayNotification::dispatch($payment->id, $gatewayReference, 'return');

        return true;
    }

    /**
     * Fails closed: a signature check that throws is a signature that did not hold.
     */
    protected function signatureHolds(PaymentGateway $driver, Request $request): bool
    {
        try {
            return $driver->verifyWebhookSignature($request);
        } catch (Throwable) {
            return false;
        }
    }
}
