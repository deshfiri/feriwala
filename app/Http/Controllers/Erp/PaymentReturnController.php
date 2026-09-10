<?php

namespace App\Http\Controllers\Erp;

use App\Domain\Billing\Actions\SettlePayment;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Payment;
use App\Http\Controllers\Controller;
use App\Integrations\Payment\Exceptions\GatewayUnavailable;
use App\Integrations\Payment\PaymentGatewayManager;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Log\LogManager;

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
    /**
     * The gateway says it went through. Ask it whether that is true.
     */
    public function success(
        Request $request,
        SettlePayment $settle,
        PaymentGatewayManager $gateways,
        LogManager $log,
    ): RedirectResponse {
        $payment = $this->paymentFor($request);

        if ($payment === null) {
            return to_route('onboarding.status');
        }

        if ($payment->isSettled()) {
            return to_route('onboarding.status')
                ->with('success', __('payment.return.received'));
        }

        $callback = $gateways->driver((string) $payment->gateway)->handleCallback($request);

        if ($callback->gatewayReference === null) {
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
        return match (true) {
            $payment->isSettled() => to_route('onboarding.status')
                ->with('success', __('payment.return.received')),

            $payment->needsReconciliation() => to_route('checkout.show')
                ->with('info', __('payment.return.reconciling')),

            default => to_route('checkout.show')->with('error', __('payment.return.failed')),
        };
    }

    /**
     * The person backed out at the gateway.
     */
    public function cancelled(Request $request): RedirectResponse
    {
        $this->recordAbandonment($request, PaymentStatus::Cancelled);

        return to_route('checkout.show')->with('info', __('payment.return.cancelled'));
    }

    /**
     * The gateway says it did not go through.
     */
    public function failed(Request $request): RedirectResponse
    {
        $this->recordAbandonment($request, PaymentStatus::Failed);

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
    protected function recordAbandonment(Request $request, PaymentStatus $to): void
    {
        $payment = $this->paymentFor($request);

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
     * The payment this return is about.
     *
     * Resolved from the transaction reference the gateway echoes back, and
     * **scoped to the caller's own account** — so a reference belonging to
     * somebody else finds nothing rather than finding their payment (§31.3).
     * Falls back to this account's latest activation attempt when the gateway
     * returns without one, which cancel URLs routinely do.
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

        $reference = $request->input('tran_id');

        $query = Payment::query()->where('business_account_id', $accountId);

        if (is_string($reference) && $reference !== '') {
            return $query->where('reference', $reference)->first();
        }

        return $query->where('purpose', PaymentPurpose::Activation)->latest('id')->first();
    }
}
