<?php

namespace App\Http\Controllers\Webhook;

use App\Domain\Billing\Actions\RecordPaymentLog;
use App\Domain\Billing\Jobs\SettleGatewayNotification;
use App\Domain\Billing\Models\PaymentLog;
use App\Domain\Billing\Queries\ResolveNotifiedPayment;
use App\Http\Controllers\Controller;
use App\Integrations\Payment\Contracts\PaymentGateway;
use App\Integrations\Payment\Data\GatewayCapability;
use App\Integrations\Payment\Data\GatewayResult;
use App\Integrations\Payment\PaymentGatewayManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Log\LogManager;
use Throwable;

/**
 * One notification endpoint for every gateway (§26.4, §17.3).
 *
 * The reliable half of payment confirmation — a browser closes mid-redirect, an
 * IPN retries. Unauthenticated by necessity, which shapes everything here.
 *
 * **Nothing in this file knows one provider from another.** The driver turns its
 * own vocabulary into a result, and the payment is found from that. Adding a
 * gateway changes a config entry, not this controller.
 *
 * **It answers fast and settles later.** Verification is a call to somebody
 * else's server and settlement touches a wallet; doing either inline means a
 * provider times out and sends the notification again, turning one message into
 * five. So this writes the message down, queues the work, and acknowledges.
 *
 * **The reply never says what was found.** Every outcome that is not a signature
 * failure answers identically — the same status, the same body — so this cannot
 * be used to ask whether a reference exists, whose it is, or whether it has been
 * paid. An endpoint anybody can post to must not be a lookup service.
 *
 * **Nothing here decides anything about money.** A notification says which
 * payment to look at. What happened to it comes from asking the gateway
 * directly, in the job, every time.
 */
class PaymentWebhookController extends Controller
{
    /**
     * The one answer this endpoint gives.
     *
     * Every outcome that is not a signature failure gets this, unchanged — a
     * payment settled, a reference nobody recognises, a notification naming no
     * transaction. A provider needs to know only that the message arrived, and
     * anything more detailed would turn an endpoint anybody can post to into a
     * way of asking which references exist.
     */
    protected const ACKNOWLEDGED = ['message' => 'Acknowledged.'];

    public function __invoke(
        Request $request,
        string $gateway,
        PaymentGatewayManager $gateways,
        ResolveNotifiedPayment $payments,
        RecordPaymentLog $logs,
        LogManager $log,
    ): JsonResponse {
        if (! $gateways->isImplemented($gateway)) {
            return response()->json(['message' => 'Unknown gateway.'], 404);
        }

        $driver = $gateways->driver($gateway);

        /*
         * A provider that cannot confirm a payment cannot be told one arrived.
         * EPS and Nagad rest here — no capabilities, so no notification from
         * them means anything and none is accepted.
         */
        if (! $driver->supports(GatewayCapability::Verify)) {
            return response()->json(['message' => 'Unknown gateway.'], 404);
        }

        /*
         * The signature, where there is one.
         *
         * Three of the eight providers do not sign their notifications, and for
         * two of those that is documented behaviour rather than an omission —
         * shurjoPay's own guidance is to verify through its API on receiving
         * one. Refusing those outright would throw away a settlement path the
         * provider designed.
         *
         * What makes accepting them safe is that a notification has never been
         * evidence here. Signed or not, it only says *which* payment to look at;
         * the job asks the gateway what actually happened. So an unsigned
         * notification buys an attacker a verification call about a payment
         * they must already know the reference of, and nothing else — and the
         * reply tells them nothing either way.
         */
        if ($driver->supports(GatewayCapability::WebhookSignature)) {
            if (! $this->hasValidSignature($driver, $request, $log, $gateway)) {
                $log->channel('payment')->warning('Rejected an unsigned payment webhook', [
                    'gateway' => $gateway,
                    'ip' => $request->ip(),
                ]);

                /*
                 * Recorded, not just logged to a file. "Somebody has been
                 * posting unsigned notifications at us" is a question with an
                 * answer only if the refusals are kept — and the payload is
                 * redacted on the way in, so keeping it costs nothing (§42).
                 */
                $logs->handle(
                    gateway: $gateway,
                    direction: PaymentLog::INBOUND,
                    event: 'ipn',
                    outcome: 'refused_signature',
                    httpStatus: 401,
                    context: $request->all(),
                    request: $request,
                );

                return response()->json(['message' => 'Invalid signature.'], 401);
            }
        }

        $result = $this->readCallback($driver, $request, $logs, $gateway, $log);

        if ($result === null) {
            return response()->json(self::ACKNOWLEDGED);
        }

        $payment = $payments->handle($gateway, $result);

        if ($payment === null) {
            /*
             * A signed notification for a payment we cannot find is the most
             * interesting row this table holds, so it is kept in full. The
             * *reply* gives nothing away — it is the same one a recognised
             * payment gets.
             */
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

            return response()->json(self::ACKNOWLEDGED);
        }

        if ($result->gatewayReference === null) {
            // Nothing to verify with. Recorded, because a notification that
            // named a payment but no transaction is worth being able to see.
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

            return response()->json(self::ACKNOWLEDGED);
        }

        $logs->handle(
            gateway: $gateway,
            direction: PaymentLog::INBOUND,
            event: 'ipn',
            payment: $payment,
            gatewayReference: $result->gatewayReference,
            outcome: 'queued',
            httpStatus: 202,
            context: $request->all(),
            request: $request,
        );

        SettleGatewayNotification::dispatch($payment->id, $result->gatewayReference, 'ipn');

        return response()->json(self::ACKNOWLEDGED);
    }

    /**
     * Check the signature without letting a driver fault read as a pass.
     *
     * PayPal verifies by asking PayPal, so this can fail for reasons that have
     * nothing to do with the notification. Any of them mean the same thing: not
     * verified.
     */
    protected function hasValidSignature(PaymentGateway $driver, Request $request, LogManager $log, string $gateway): bool
    {
        try {
            return $driver->verifyWebhookSignature($request);
        } catch (Throwable $exception) {
            $log->channel('payment')->error('Signature verification threw', [
                'gateway' => $gateway,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Ask the driver what the request says, tolerating a driver that refuses.
     *
     * A malformed notification is not worth a 500 — the provider would retry it
     * and get the same 500 — so it is recorded and acknowledged.
     */
    protected function readCallback(
        PaymentGateway $driver,
        Request $request,
        RecordPaymentLog $logs,
        string $gateway,
        LogManager $log,
    ): ?GatewayResult {
        try {
            return $driver->handleCallback($request);
        } catch (Throwable $exception) {
            $log->channel('payment')->error('Could not read a payment webhook', [
                'gateway' => $gateway,
                'error' => $exception->getMessage(),
            ]);

            $logs->handle(
                gateway: $gateway,
                direction: PaymentLog::INBOUND,
                event: 'ipn',
                outcome: 'unreadable',
                httpStatus: 200,
                context: $request->all(),
                request: $request,
            );

            return null;
        }
    }
}
