<?php

namespace App\Http\Controllers\Erp;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\Actions\CalculateActivationQuote;
use App\Domain\Billing\Actions\RecordPaymentFromQuote;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Package\Enums\UserPackageStatus;
use App\Domain\Package\Models\UserPackage;
use App\Http\Controllers\Controller;
use App\Integrations\Payment\Data\PaymentIntent;
use App\Integrations\Payment\Exceptions\GatewayUnavailable;
use App\Integrations\Payment\PaymentGatewayManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The combined registration and package fee checkout (§9).
 *
 * The breakdown shown here is recalculated server-side on every view and again
 * at payment. Nothing about the amount comes from the browser — a form field
 * carrying a total would be the obvious thing to edit (§36.1).
 */
class CheckoutController extends Controller
{
    use ResolvesBusinessAccount;

    public function show(
        Request $request,
        CalculateActivationQuote $quotes,
        PaymentGatewayManager $gateways,
    ): Response|RedirectResponse {
        $account = $this->businessAccountFor($request);

        $subscription = $this->pendingSubscription($account);
        $package = $subscription?->package;

        // A subscription whose package has gone is not something to check out —
        // send them back to choose rather than rendering a broken summary.
        if ($package === null) {
            return to_route('packages.index')
                ->with('info', 'Choose a package to continue.');
        }

        $quote = $quotes->handle($package);

        return Inertia::render('onboarding/checkout', [
            'package' => [
                'slug' => $package->slug,
                'name' => $package->name,
                'validity_days' => $package->validity_days,
            ],
            'quote' => $quote->toArray(),
            'gateways' => array_map(fn (string $name) => [
                'name' => $name,
                'label' => config("payment.gateways.{$name}.label", $name),
            ], $gateways->available()),
        ]);
    }

    /**
     * Record the payment and hand off to the gateway.
     */
    public function pay(
        Request $request,
        CalculateActivationQuote $quotes,
        RecordPaymentFromQuote $record,
        PaymentGatewayManager $gateways,
    ): RedirectResponse {
        $account = $this->businessAccountFor($request);

        $validated = $request->validate([
            'gateway' => ['required', 'string', Rule::in($gateways->available())],
        ]);

        $subscription = $this->pendingSubscription($account);
        $package = $subscription?->package;

        if ($subscription === null || $package === null) {
            return to_route('packages.index');
        }

        // Recalculated here rather than trusting anything submitted.
        $quote = $quotes->handle($package);

        if (! $quote->isPayable()) {
            throw ValidationException::withMessages([
                'gateway' => 'There is nothing to pay for this package.',
            ]);
        }

        $payment = $record->handle(
            account: $account,
            quote: $quote,
            purpose: PaymentPurpose::Activation,
            // Ties the payment to this subscription attempt, so a double-submit
            // reuses the same payment rather than creating a second one (§26.4).
            idempotencyKey: 'activation:'.$subscription->public_id,
            payable: $subscription,
        );

        $payment->forceFill(['gateway' => $validated['gateway']])->save();

        try {
            $redirect = $gateways->driver($validated['gateway'])->initiate(
                PaymentIntent::forPayment(
                    $payment,
                    successUrl: route('checkout.return'),
                    failUrl: route('checkout.return'),
                    cancelUrl: route('checkout.return'),
                    ipnUrl: route('webhooks.payment', $validated['gateway']),
                ),
            );
        } catch (GatewayUnavailable $e) {
            // The payment row stays as a draft — the user can try again, or a
            // different gateway, without losing the quote.
            throw ValidationException::withMessages(['gateway' => $e->getMessage()]);
        }

        if ($payment->canTransitionTo(PaymentStatus::Initiated)) {
            $payment->transitionTo(PaymentStatus::Initiated);
            $payment->forceFill(['initiated_at' => now()])->save();
        }

        return redirect()->away($redirect->url);
    }

    protected function pendingSubscription(BusinessAccount $account): ?UserPackage
    {
        return $account->packages()
            ->where('status', UserPackageStatus::PendingPayment)
            ->with('package.features')
            ->first();
    }
}
