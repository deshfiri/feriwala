<?php

namespace App\Http\Controllers\Webhook;

use App\Domain\Billing\Actions\SettlePayment;
use App\Domain\Billing\Models\Payment;
use App\Http\Controllers\Controller;
use App\Integrations\Payment\Exceptions\GatewayUnavailable;
use App\Integrations\Payment\PaymentGatewayManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Log\LogManager;

/**
 * Gateway IPN endpoint (§26.4, §17.3).
 *
 * The reliable half of payment confirmation — a browser closes mid-redirect,
 * an IPN retries.
 *
 * Unauthenticated by necessity, so the signature is the only thing standing
 * between this and an anonymous request claiming money arrived. It is checked
 * first, before the payment is even looked up.
 */
class PaymentWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        string $gateway,
        PaymentGatewayManager $gateways,
        SettlePayment $settle,
        LogManager $log,
    ): JsonResponse {
        if (! $gateways->isAvailable($gateway)) {
            return response()->json(['message' => 'Unknown gateway.'], 404);
        }

        $driver = $gateways->driver($gateway);

        if (! $driver->verifyWebhookSignature($request)) {
            $log->channel('payment')->warning('Rejected an unsigned payment webhook', [
                'gateway' => $gateway,
                'ip' => $request->ip(),
            ]);

            // Deliberately says nothing about whether the payment exists.
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $result = $driver->handleCallback($request);

        $payment = $result->reference === null
            ? null
            : Payment::query()->where('reference', $result->reference)->first();

        if ($payment === null) {
            $log->channel('payment')->warning('Signed webhook for an unknown payment', [
                'gateway' => $gateway,
                'reference' => $result->reference,
            ]);

            // 200 on purpose: the signature was valid, so this is our problem,
            // not the gateway's. Returning an error would make it retry
            // something that can never succeed.
            return response()->json(['message' => 'Acknowledged.']);
        }

        if ($result->gatewayReference === null) {
            return response()->json(['message' => 'Acknowledged.']);
        }

        try {
            $settle->handle($payment, $result->gatewayReference);
        } catch (GatewayUnavailable $e) {
            $log->channel('payment')->error('Verification failed during webhook', [
                'payment' => $payment->reference,
                'error' => $e->getMessage(),
            ]);

            // 503 so the gateway retries — the payment is genuinely unresolved.
            return response()->json(['message' => 'Verification unavailable.'], 503);
        }

        return response()->json(['message' => 'Acknowledged.']);
    }
}
