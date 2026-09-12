<?php

namespace App\Http\Controllers\Erp;

use App\Domain\Billing\Actions\RecordPaymentLog;
use App\Domain\Billing\Actions\SettlePayment;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\PaymentLog;
use App\Http\Controllers\Controller;
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
 * as "we are checking" rather than "it failed". Cancel and fail never verify
 * anything at all — there is nothing to confirm, and a redirect claiming failure
 * must not be able to close a payment that actually succeeded.
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

        if ($callback === null || $callback->gatewayReference === null) {
            // Returned to the success URL without a transaction to check. The
            // IPN is the reliable half; say nothing definite.
            return to_route('checkout.show')->with('info', __('payment.return.checking'));
        }

        try {
            $settle->handle($payment, $callback->gatewayReference);
        } catch (GatewayUnavailable $e) {
            // Not a failure — we simply could not ask. The IPN will settle it.
            $log->channel('payment')->warning('Could not verify on return', [
                'payment' => $payment->reference,
                'error' => $e->getMessage(),
            ]);

            return to_route('onboarding.status')->with('info', __('payment.return.checking'));
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

            default => to_route('checkout.show')->with('error', __('payment.return.failed')),
        };
    }

    /**
     * The person backed out at the gateway.
     */
    public function cancelled(Request $request): RedirectResponse
    {
        $this->recordAbandonment($request, PaymentStatus::Cancelled, 'cancel');

        return to_route('checkout.show')->with('info', __('payment.return.cancelled'));
    }

    /**
     * The gateway says it did not go through.
     */
    public function failed(Request $request): RedirectResponse
    {
        $this->recordAbandonment($request, PaymentStatus::Failed, 'fail');

        return to_route('checkout.show')->with('error', __('payment.return.failed'));
    }

    /**
     * Close a payment the gateway says never happened.
     *
     * The browser is trusted for this and only this: it can close an attempt
     * nobody paid for, and it can never open one. A settled payment is left
     * alone entirely — a forged failure redirect must not be able to undo money
     * that arrived, and if the gateway is wrong the IPN still corrects it into
     * reconciliation rather than into activation.
     */
    protected function recordAbandonment(Request $request, PaymentStatus $to, string $event): void
    {
        $payment = $this->paymentFor($request);

        $this->record($request, $event, $payment);

        if ($payment === null || ! $payment->canTransitionTo($to)) {
            return;
        }

        $payment->transitionTo($to);

        $payment->forceFill($to === PaymentStatus::Cancelled
            ? ['cancelled_at' => now()]
            : ['failed_at' => now(), 'failure_reason' => __('payment.return.failed')])
            ->save();
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
         */
        $open = Payment::query()
            ->where('business_account_id', $accountId)
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
