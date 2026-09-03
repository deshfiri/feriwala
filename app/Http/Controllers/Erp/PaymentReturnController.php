<?php

namespace App\Http\Controllers\Erp;

use App\Domain\Billing\Actions\SettlePayment;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Models\Payment;
use App\Http\Controllers\Controller;
use App\Integrations\Payment\Exceptions\GatewayUnavailable;
use App\Integrations\Payment\PaymentGatewayManager;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Log\LogManager;

/**
 * Where the gateway sends the user back to (§26.4).
 *
 * This exists for the person, not for the money. The IPN is what settles a
 * payment reliably; a browser can close mid-redirect and often does. So this
 * attempts settlement for a quick answer, and treats failure to get one as
 * "we are checking" rather than "it failed" — the IPN will still arrive.
 */
class PaymentReturnController extends Controller
{
    public function __invoke(
        Request $request,
        SettlePayment $settle,
        PaymentGatewayManager $gateways,
        LogManager $log,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();

        $payment = Payment::query()
            ->where('business_account_id', $user->businessAccount?->id)
            ->where('purpose', PaymentPurpose::Activation)
            ->latest('id')
            ->first();

        if ($payment === null) {
            return to_route('onboarding.status');
        }

        if ($payment->isSettled()) {
            return to_route('onboarding.status')
                ->with('success', 'Payment received. We are reviewing your application.');
        }

        $callback = $gateways->driver((string) $payment->gateway)->handleCallback($request);

        if ($callback->gatewayReference === null) {
            // Cancelled, or returned without a reference. Nothing to verify.
            return to_route('checkout.show')
                ->with('info', 'Your payment was not completed. You can try again.');
        }

        try {
            $result = $settle->handle($payment, $callback->gatewayReference);
        } catch (GatewayUnavailable $e) {
            // Not a failure — we simply could not ask. The IPN will settle it.
            $log->channel('payment')->warning('Could not verify on return', [
                'payment' => $payment->reference,
                'error' => $e->getMessage(),
            ]);

            return to_route('onboarding.status')
                ->with('info', 'We are confirming your payment. This page will update shortly.');
        }

        return $result->isPaid()
            ? to_route('onboarding.status')
                ->with('success', 'Payment received. We are reviewing your application.')
            : to_route('checkout.show')
                ->with('error', 'Your payment was not completed. You can try again.');
    }
}
