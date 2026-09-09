<?php

namespace App\Http\Controllers\Erp;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\Actions\CalculateRenewalQuote;
use App\Domain\Billing\Actions\RecordPaymentFromQuote;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Package\Actions\OpenRenewal;
use App\Domain\Package\Enums\UserPackageStatus;
use App\Domain\Package\Models\UserPackage;
use App\Domain\Package\Queries\AccountSubscription;
use App\Domain\Package\SubscriptionPolicy;
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
 * Renewing the term an account is on (§8.2, §8.4).
 *
 * Self-scoped: the account comes from the signed-in person's membership, never
 * from the URL (§31.3). Named under `subscription.` so it stays reachable
 * through the §5.4 gate — §8.4 keeps "limited access to Payment and renewal
 * modules" open after a term expires, and a renewal page that closes at expiry
 * closes exactly when it is needed.
 *
 * The quote is recalculated server-side on every view and again at payment.
 * Nothing about the amount comes from the browser (§36.1).
 */
class RenewalController extends Controller
{
    use ResolvesBusinessAccount;

    public function __construct(
        protected SubscriptionPolicy $policy,
        protected OpenRenewal $open,
        protected CalculateRenewalQuote $quotes,
    ) {}

    public function show(
        Request $request,
        AccountSubscription $subscriptions,
        PaymentGatewayManager $gateways,
    ): Response|RedirectResponse {
        $account = $this->businessAccountFor($request);
        $current = $this->currentTerm($account);

        $blocker = $this->policy->renewalBlocker($current);

        if ($blocker !== null || $current === null) {
            return to_route('subscription.show')->with('info', $blocker);
        }

        // Opening the renewal here rather than at payment is what makes the
        // quote and the record agree: the terms shown are the ones captured.
        $renewal = $this->open->handle($account, $current);
        $terms = $renewal->terms();

        if ($terms === null) {
            return to_route('subscription.show')
                ->with('info', __('package.renewal.terms_unavailable'));
        }

        $quote = $this->quotes->handle($terms, account: $account);

        return Inertia::render('settings/renewal', [
            'current' => $subscriptions->current($account),
            'renewal' => [
                'id' => $renewal->public_id,
                'package' => $terms->name,
                'validity_days' => $terms->validityDays,
                'grace_period_days' => $terms->gracePeriodDays,
                'renewal_frequency' => $terms->renewalFrequency,

                // When the renewed term starts: where the current one ends, so
                // renewing early costs nothing in days.
                'starts_at' => ($current->expires_at?->isFuture() ? $current->expires_at : now())
                    ->toIso8601String(),
            ],
            'quote' => $quote->toArray(),
            'gateways' => array_map(fn (string $name) => [
                'name' => $name,
                'label' => config("payment.gateways.{$name}.label", $name),
            ], $gateways->available()),
        ]);
    }

    /**
     * Record the renewal payment and hand off to the gateway.
     */
    public function pay(
        Request $request,
        RecordPaymentFromQuote $record,
        PaymentGatewayManager $gateways,
    ): RedirectResponse {
        $account = $this->businessAccountFor($request);

        $validated = $request->validate([
            'gateway' => ['required', 'string', Rule::in($gateways->available())],
        ]);

        $renewal = $this->open->pendingRenewalFor($account);
        $terms = $renewal?->terms();

        if ($renewal === null || $terms === null) {
            return to_route('subscription.renew.show');
        }

        // Recalculated here rather than trusting anything submitted.
        $quote = $this->quotes->handle($terms, account: $account);

        if (! $quote->isPayable()) {
            throw ValidationException::withMessages([
                'gateway' => __('package.renewal.nothing_to_pay'),
            ]);
        }

        $payment = $record->handle(
            account: $account,
            quote: $quote,
            purpose: PaymentPurpose::PackageRenewal,
            // Keyed on the renewal record, so a double-submit reuses the same
            // payment rather than opening a second one against one term (§26.4).
            idempotencyKey: 'renewal:'.$renewal->public_id,
            payable: $renewal,
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
            // The payment stays a draft, so the quote is not lost and another
            // gateway can be tried.
            throw ValidationException::withMessages(['gateway' => $e->getMessage()]);
        }

        if ($payment->canTransitionTo(PaymentStatus::Initiated)) {
            $payment->transitionTo(PaymentStatus::Initiated);
            $payment->forceFill(['initiated_at' => now()])->save();
        }

        return redirect()->away($redirect->url);
    }

    /**
     * The term this account is actually on.
     *
     * The pointer where its status agrees, and the newest entitling term
     * otherwise — cancelling or expiring does not clear the pointer, and
     * renewing a closed term would carry on from something that had ended.
     */
    protected function currentTerm(BusinessAccount $account): ?UserPackage
    {
        $pointed = $account->currentPackage()->with('package')->first();

        if ($pointed !== null && $this->policy->isRenewable($pointed)) {
            return $pointed;
        }

        return $account->packages()
            ->with('package')
            ->whereIn('status', [
                UserPackageStatus::Active,
                UserPackageStatus::RenewalDue,
                UserPackageStatus::GracePeriod,
                UserPackageStatus::Expired,
            ])
            ->latest('id')
            ->first();
    }
}
