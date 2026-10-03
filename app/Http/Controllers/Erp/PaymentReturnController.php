<?php

namespace App\Http\Controllers\Erp;

use App\Domain\Billing\Actions\ReconcileGatewayPayments;
use App\Domain\Billing\Actions\RecordPaymentLog;
use App\Domain\Billing\Actions\SettlePayment;
use App\Domain\Billing\Actions\VerifyGatewayReturn;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\PaymentLog;
use App\Http\Controllers\Controller;
use App\Integrations\Payment\Data\GatewayCapability;
use App\Integrations\Payment\Data\GatewayResult;
use App\Integrations\Payment\Exceptions\GatewayUnavailable;
use App\Integrations\Payment\PaymentGatewayManager;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Log\LogManager;
use Throwable;

/**
 * Where the gateway sends the person back to (§26.4).
 *
 * **Three endpoints, not one.** A gateway is told separately where success,
 * failure and cancellation go, and it uses them to say which happened. Folding
 * them into one URL throws that away and leaves the application guessing from a
 * status field the browser could have edited — so each has its own route, and
 * none of them decides anything on its own.
 *
 * This exists for the person, not for the money. The IPN is what settles a
 * payment reliably; a browser closes mid-redirect and often does. So success
 * attempts a verification for a quick answer and treats a failure to *get* one
 * as "we are checking" rather than "it failed". Cancel and fail change nothing
 * on the browser's word — a redirect claiming failure must not be able to close
 * a payment, succeeded or not.
 *
 * These are the **GET** pages, inside the session. A gateway that posts the
 * person back reaches {@see GatewayReturnController} first, which
 * sends them here.
 */
class PaymentReturnController extends Controller
{
    public function __construct(
        protected RecordPaymentLog $logs,
        protected PaymentGatewayManager $gateways,
    ) {}

    /**
     * The gateway says it went through. Ask it whether that is true.
     */
    public function success(
        Request $request,
        SettlePayment $settle,
        LogManager $log,
    ): RedirectResponse {
        $payment = $this->paymentFor($request);

        $this->record($request, 'return', $payment);

        if ($payment === null) {
            return to_route('onboarding.status');
        }

        if ($payment->isSettled()) {
            return to_route('onboarding.status')
                ->with('success', __('payment.return.received'));
        }

        $callback = $this->callbackFor($payment, $request);

        /*
         * Where to send a payer who still has no definite answer once every
         * attempt below is exhausted — kept distinct from the generic "still
         * open" landing a moment later, exactly as it was before the direct
         * lookup was added: a callback with nothing usable in it reads as an
         * ordinary "keep waiting", while the gateway being unreachable reads
         * as "we genuinely could not ask" and goes to the status page instead
         * of back to checkout.
         */
        $unresolved = fn () => to_route('checkout.show')->with('info', __('payment.return.checking'));

        if ($callback !== null && $callback->gatewayReference !== null) {
            try {
                $settle->handle($payment, $callback->gatewayReference);
            } catch (GatewayUnavailable $e) {
                $log->channel('payment')->warning('Could not verify on return', [
                    'payment' => $payment->reference,
                    'error' => $e->getMessage(),
                ]);

                $unresolved = fn () => to_route('onboarding.status')->with('info', __('payment.return.checking'));
            }
        }

        /*
         * Still not settled — ask the gateway directly, by our own
         * reference, right now: the same lookup the hourly reconciliation
         * sweep uses, just not deferred. A customer who genuinely paid
         * should not have to wait on the IPN or that sweep, or have staff
         * fix it by hand, to be told so.
         */
        if (! $payment->refresh()->isSettled()) {
            $this->reconcileNow($payment, $settle, $log);
        }

        /*
         * Read from the payment rather than the gateway's answer. A verified
         * success against a checkout that had already expired lands in
         * reconciliation, which is neither a success nor a "try again" — and
         * telling somebody to pay again there would take their money twice.
         */
        $status = $payment->refresh()->status;

        return match (true) {
            $status->isSettled() => to_route('onboarding.status')
                ->with('success', __('payment.return.received')),

            $status->needsReconciliation() => to_route('checkout.show')
                ->with('info', __('payment.return.reconciling')),

            in_array($status, PaymentStatus::open(), true) => $unresolved(),
            default => to_route('checkout.show')->with('error', __('payment.return.failed')),
        };
    }

    /**
     * Ask the gateway directly whether this payment went through, by our own
     * reference, instead of waiting for the hourly reconciliation sweep to
     * get to it (§28.1).
     *
     * Only for providers that publish a status lookup — the same gate
     * {@see ReconcileGatewayPayments} uses, since this is that same check,
     * just run immediately rather than deferred. Never allowed to turn this
     * page into an error: a provider that cannot
     * be reached, or has nothing to say, leaves the payment exactly as it
     * was for the sweep to pick up later.
     */
    protected function reconcileNow(Payment $payment, SettlePayment $settle, LogManager $log): void
    {
        $gateway = (string) $payment->gateway;

        if ($gateway === '' || ! $this->gateways->isImplemented($gateway)) {
            return;
        }

        $driver = $this->gateways->driver($gateway);

        if (! $driver->supports(GatewayCapability::StatusQuery)) {
            return;
        }

        try {
            $result = $driver->status($payment->reference);
        } catch (GatewayUnavailable $e) {
            $log->channel('payment')->warning('Could not reconcile on return', [
                'payment' => $payment->reference,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if (! $result->isPaid() || $result->gatewayReference === null) {
            return;
        }

        try {
            $settle->handle($payment, $result->gatewayReference);
        } catch (GatewayUnavailable) {
            // Asked twice in one request and still could not confirm — the
            // sweep will try again shortly.
        }
    }

    /**
     * The person backed out at the gateway.
     */
    public function cancelled(Request $request, VerifyGatewayReturn $verify): RedirectResponse
    {
        $this->acknowledge($request, 'cancel', $verify);

        return to_route('checkout.show')->with('info', __('payment.return.cancelled'));
    }

    /**
     * The gateway says it did not go through.
     */
    public function failed(Request $request, VerifyGatewayReturn $verify): RedirectResponse
    {
        $this->acknowledge($request, 'fail', $verify);

        return to_route('checkout.show')->with('error', __('payment.return.failed'));
    }

    /**
     * Note a cancelled or failed return — and change nothing on its word.
     *
     * A cancel or failure address is only a claim, and one anybody can send a
     * signed-in person to. So it closes nothing: the attempt stays open for the
     * gateway to confirm, the payment's own deadline, or the reconciliation
     * sweep to settle. Closing it here also broke the next attempt, which reuses
     * the same payment and would have taken real money onto a closed one.
     *
     * When the return carries something that can be checked, the gateway is
     * asked — and its answer, not this request, decides.
     */
    protected function acknowledge(Request $request, string $event, VerifyGatewayReturn $verify): void
    {
        $payment = $this->paymentFor($request);

        $this->record($request, $event, $payment);

        $result = $payment === null ? null : $this->callbackFor($payment, $request);

        if ($payment !== null && $result !== null) {
            $verify->handle($payment, $request, $result, $event);
        }
    }

    /**
     * Keep the redirect on the record (§42).
     *
     * A browser redirect is the least trustworthy of the three ways a gateway
     * reports an outcome, which is exactly why it is worth having: when a
     * customer says "it told me it worked" and the IPN says otherwise, this is
     * the only place that remembers what they saw.
     */
    protected function record(Request $request, string $event, ?Payment $payment): void
    {
        $gateway = $payment?->gateway;
        $gateway = is_string($gateway) && $gateway !== '' ? $gateway : 'unknown';

        $result = $payment === null ? null : $this->callbackFor($payment, $request);

        $this->logs->handle(
            gateway: $gateway,
            direction: PaymentLog::INBOUND,
            event: $event,
            payment: $payment,

            // Read through the driver, never from a field name. Every provider
            // calls its transaction something different and this controller
            // knows none of them.
            reference: $result?->reference,
            gatewayReference: $result?->gatewayReference,
            outcome: $result?->outcome->value,
            context: $request->all(),
            request: $request,
        );
    }

    /**
     * What the gateway's own driver makes of this request.
     *
     * Wrapped because a driver may refuse a malformed return outright, and a
     * person who has just paid should not meet a 500 on the way back.
     */
    protected function callbackFor(Payment $payment, Request $request): ?GatewayResult
    {
        try {
            return $this->gateways->driver((string) $payment->gateway)->handleCallback($request);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The payment this return is about.
     *
     * **Scoped to the caller's own account**, so a reference belonging to
     * somebody else finds nothing rather than finding their payment (§31.3).
     *
     * Which payment that is comes from the account's own open attempt rather
     * than from anything in the request. Every provider returns with a different
     * field, several return with nothing identifiable at all, and none of it is
     * trustworthy — so this asks the question the session can answer instead:
     * what was this account in the middle of paying?
     */
    protected function paymentFor(Request $request): ?Payment
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return null;
        }

        $accountId = $user->businessAccount?->id;

        if ($accountId === null) {
            return null;
        }

        /*
         * The most recent payment this account started and has not finished.
         * A settled one is excluded so a fresh attempt is never confused with
         * the one before it, and the fallback keeps the old behaviour for an
         * account whose only payment has already closed — which is what a
         * cancel URL routinely arrives against.
         *
         * Never an order's payment. Those return to addresses that
         * name their order, and closing one here would leave the order's stock
         * held for a payment that no longer exists (P4-9).
         */
        $open = Payment::query()
            ->where('business_account_id', $accountId)
            ->whereNotIn('purpose', [PaymentPurpose::WholesaleOrder, PaymentPurpose::WebsiteOrder])
            ->whereIn('status', PaymentStatus::open())
            ->latest('id')
            ->first();

        if ($open !== null) {
            return $open;
        }

        return Payment::query()
            ->where('business_account_id', $accountId)
            ->where('purpose', PaymentPurpose::Activation)
            ->latest('id')
            ->first();
    }
}
