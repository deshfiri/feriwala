<?php

namespace App\Http\Controllers\Webhook;

use App\Domain\Billing\Actions\RecordPaymentLog;
use App\Domain\Billing\Actions\SettlePayment;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\PaymentLog;
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
        RecordPaymentLog $logs,
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

            /*
             * Recorded, not just logged to a file. "Somebody has been posting
             * unsigned notifications at us" is a question with an answer only
             * if the refusals are kept — and the payload is redacted on the way
             * in, so keeping it costs nothing (§42).
             */
            $logs->handle(
                gateway: $gateway,
                direction: PaymentLog::INBOUND,
                event: 'ipn',
                reference: $request->input('tran_id'),
                outcome: 'refused_signature',
                httpStatus: 401,
                context: $request->all(),
                request: $request,
            );

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

            // The most interesting entry in the table: a genuine notification
            // for a payment we cannot find.
            $logs->handle(
                gateway: $gateway,
                direction: PaymentLog::INBOUND,
                event: 'ipn',
                reference: $result->reference,
                gatewayReference: $result->gatewayReference,
                outcome: 'unknown_payment',
                httpStatus: 200,
                context: $request->all(),
                request: $request,
            );

            // 200 on purpose: the signature was valid, so this is our problem,
            // not the gateway's. Returning an error would make it retry
            // something that can never succeed.
            return response()->json(['message' => 'Acknowledged.']);
        }

        if ($result->gatewayReference === null) {
            $logs->handle(
                gateway: $gateway,
                direction: PaymentLog::INBOUND,
                event: 'ipn',
                payment: $payment,
                outcome: 'no_transaction',
                httpStatus: 200,
                context: $request->all(),
                request: $request,
            );

            return response()->json(['message' => 'Acknowledged.']);
        }

        $logs->handle(
            gateway: $gateway,
            direction: PaymentLog::INBOUND,
            event: 'ipn',
            payment: $payment,
            gatewayReference: $result->gatewayReference,
            outcome: 'accepted',
            httpStatus: 200,
            context: $request->all(),
            request: $request,
        );

        try {
            $settle->handle($payment, $result->gatewayReference);
        } catch (GatewayUnavailable $e) {
            $log->channel('payment')->error('Verification failed during webhook', [
                'payment' => $payment->reference,
                'error' => $e->getMessage(),
            ]);

            $logs->handle(
                gateway: $gateway,
                direction: PaymentLog::INBOUND,
                event: 'ipn',
                payment: $payment,
                gatewayReference: $result->gatewayReference,
                outcome: 'verification_unavailable',
                httpStatus: 503,
                context: ['error' => $e->getMessage()],
                request: $request,
            );

            // 503 so the gateway retries — the payment is genuinely unresolved.
            return response()->json(['message' => 'Verification unavailable.'], 503);
        }

        return response()->json(['message' => 'Acknowledged.']);
    }
}
