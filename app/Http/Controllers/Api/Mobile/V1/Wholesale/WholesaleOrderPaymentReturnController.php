<?php

namespace App\Http\Controllers\Api\Mobile\V1\Wholesale;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Billing\Actions\RecordPaymentLog;
use App\Domain\Billing\Actions\SettlePayment;
use App\Domain\Billing\Actions\VerifyGatewayReturn;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\PaymentLog;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Queries\WholesaleOrderTracking;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Integrations\Payment\Data\GatewayResult;
use App\Integrations\Payment\Exceptions\GatewayUnavailable;
use App\Integrations\Payment\PaymentGatewayManager;
use App\Support\Concurrency\Exceptions\LockTimeout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Log\LogManager;
use Throwable;

/**
 * Where the app reports that the gateway sent the person back after paying for
 * a wholesale order (§14, §26.4).
 *
 * The same rules as the web return, with the order named in the address:
 * scoped to the signed-in person's own account, so another account's order is a
 * `404`, and nothing decided on the app's word. What the app forwards is only
 * what the gateway put on the return address; it is checked with the gateway
 * through the one settlement path, never believed.
 *
 *   - `return` asks the gateway and then reads the order as it now stands.
 *   - `failed` and `cancelled` change nothing by themselves: the order keeps
 *     waiting for the gateway to confirm, for its window to close, or for the
 *     buyer to cancel it.
 *
 * Every answer is the order's current standing: `outcome` and a `message`.
 */
class WholesaleOrderPaymentReturnController extends MobileController
{
    use ResolvesBusinessAccount;

    public function __construct(
        protected WholesaleOrderTracking $tracking,
        protected RecordPaymentLog $logs,
        protected PaymentGatewayManager $gateways,
    ) {}

    /**
     * Payment returned
     *
     * Send the query parameters the gateway returned with. The gateway is asked
     * about the payment; the answer says whether the order is now `paid`, `held`
     * for review, `reconciling`, still `checking`, or `failed`.
     *
     * @response array{outcome: string, message: string, data: array<string, mixed>}
     */
    public function success(Request $request, string $order, SettlePayment $settle, LogManager $log): JsonResponse
    {
        $record = $this->orderFor($request, $order);
        $payment = $record->payment;

        $this->record($request, 'return', $record, $payment);

        if ($payment !== null && ! $payment->isSettled()) {
            $callback = $this->callbackFor($payment, $request);

            if ($callback === null || $callback->gatewayReference === null) {
                return $this->standingOf($record->refresh());
            }

            try {
                $settle->handle($payment, $callback->gatewayReference);
            } catch (GatewayUnavailable|LockTimeout $exception) {
                // Not a failure: we could not ask yet. The IPN settles it.
                $log->channel('payment')->warning('Could not verify a wholesale order payment on return', [
                    'order' => $record->reference,
                    'payment' => $payment->reference,
                    'error' => $exception->getMessage(),
                ]);

                return $this->respond($record, 'checking', 'orders.flash.checking');
            }
        }

        return $this->standingOf($record->refresh());
    }

    /**
     * Payment cancelled
     *
     * The person backed out at the gateway. Changes nothing by itself; the order
     * keeps waiting while its payment window is open.
     *
     * @response array{outcome: string, message: string, data: array<string, mixed>}
     */
    public function cancelled(Request $request, string $order, VerifyGatewayReturn $verify): JsonResponse
    {
        return $this->acknowledge($request, $order, $verify, 'cancel');
    }

    /**
     * Payment failed
     *
     * The gateway reported a failure. Changes nothing by itself; the order keeps
     * waiting while its payment window is open.
     *
     * @response array{outcome: string, message: string, data: array<string, mixed>}
     */
    public function failed(Request $request, string $order, VerifyGatewayReturn $verify): JsonResponse
    {
        return $this->acknowledge($request, $order, $verify, 'fail');
    }

    protected function acknowledge(Request $request, string $order, VerifyGatewayReturn $verify, string $event): JsonResponse
    {
        $record = $this->orderFor($request, $order);
        $payment = $record->payment;

        $this->record($request, $event, $record, $payment);

        $result = $payment === null ? null : $this->callbackFor($payment, $request);

        if ($payment !== null && $result !== null) {
            $verify->handle($payment, $request, $result, $event);
        }

        $record->refresh();

        if ($record->status === OrderStatus::PaymentPending) {
            return $this->respond($record, 'not_completed', 'orders.flash.payment_not_completed');
        }

        return $this->standingOf($record);
    }

    /**
     * What to tell the person about the order as it stands now.
     */
    protected function standingOf(Order $order): JsonResponse
    {
        $payment = $order->payment()->first();

        [$outcome, $message] = match (true) {
            $order->status === OrderStatus::Paid => ['paid', 'orders.flash.paid'],
            $order->status === OrderStatus::OnHold => ['held', 'orders.flash.held'],
            $payment?->status->needsReconciliation() ?? false => ['reconciling', 'orders.flash.reconciling'],
            $order->status === OrderStatus::PaymentPending => ['checking', 'orders.flash.checking'],
            default => ['failed', 'orders.flash.payment_failed'],
        };

        return $this->respond($order, $outcome, $message);
    }

    protected function respond(Order $order, string $outcome, string $message): JsonResponse
    {
        $fresh = $this->tracking->find($order->businessAccount, $order->public_id) ?? $order;

        return new JsonResponse([
            'outcome' => $outcome,
            'message' => __($message),
            'data' => $this->tracking->detail($fresh),
        ]);
    }

    /**
     * Keep the redirect on the record (§42), as the web return does.
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
