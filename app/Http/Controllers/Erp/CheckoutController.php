<?php

namespace App\Http\Controllers\Erp;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\Actions\CalculateActivationQuote;
use App\Domain\Billing\Actions\RecordPaymentFromQuote;
use App\Domain\Billing\Actions\ReserveCoupon;
use App\Domain\Billing\CouponValidator;
use App\Domain\Billing\Data\ActivationQuote;
use App\Domain\Billing\Data\CouponOutcome;
use App\Domain\Billing\Enums\AllocationType;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Package\Enums\UserPackageStatus;
use App\Domain\Package\Models\Package;
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
use RuntimeException;

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

    /**
     * Where a typed coupon code is held between the render and the payment.
     *
     * The session, not a form field or the URL: the code is revalidated
     * server-side every time it is read, so nothing the browser holds decides
     * what is charged (§36.1).
     */
    public const COUPON_SESSION_KEY = 'checkout.coupon';

    public function __construct(
        protected CouponValidator $coupons,
        protected ReserveCoupon $reserveCoupon,
    ) {}

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

        /*
         * The coupon held in the session, re-validated on every render. Nothing
         * is reserved here: checking what a code is worth must not spend it
         * (§9), and this runs every time the page is opened.
         */
        $coupon = $this->couponOutcome($request, $account, $package, $quotes);

        $quote = $this->quoteFor($quotes, $package, $account, $coupon);

        return Inertia::render('onboarding/checkout', [
            'package' => [
                'slug' => $package->slug,
                'name' => $package->name,
                'validity_days' => $package->validity_days,
            ],
            'quote' => $quote->toArray(),
            'coupon' => $coupon?->toArray(),
            'gateways' => array_map(fn (string $name) => [
                'name' => $name,
                'label' => config("payment.gateways.{$name}.label", $name),
            ], $gateways->available()),
        ]);
    }

    /**
     * Hold a coupon code against this checkout, or clear it.
     *
     * Kept in the session rather than in the URL or a form field: the code is
     * revalidated server-side on every render and again at payment, so nothing
     * the browser holds decides what is charged (§36.1).
     */
    public function applyCoupon(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['nullable', 'string', 'max:40'],
        ]);

        $code = trim((string) ($validated['code'] ?? ''));

        $code === ''
            ? $request->session()->forget(self::COUPON_SESSION_KEY)
            : $request->session()->put(self::COUPON_SESSION_KEY, $code);

        return back();
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

        // Recalculated here rather than trusting anything submitted — the
        // coupon included, so a code that expired while the page was open is
        // refused at the moment it matters rather than honoured from a stale
        // render (§36.1).
        $coupon = $this->couponOutcome($request, $account, $package, $quotes);
        $quote = $this->quoteFor($quotes, $package, $account, $coupon);

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

        /*
         * The coupon is held now, not when the page was opened (§9). This is
         * the moment somebody commits to paying, and the hold is what stops two
         * checkouts started at once from both spending the last slot. It
         * becomes a redemption when the money arrives and is released if it
         * never does.
         */
        if ($coupon !== null && $coupon->isAccepted && $coupon->coupon !== null) {
            try {
                $this->reserveCoupon->handle(
                    $coupon->coupon,
                    $account,
                    $payment,
                    $coupon->discount ?? $quote->amountFor(AllocationType::Discount),
                );
            } catch (RuntimeException $exception) {
                // The last slot went between the render and here. Better to
                // refuse the checkout than to charge a discounted total against
                // a coupon that is no longer available.
                $request->session()->forget(self::COUPON_SESSION_KEY);

                throw ValidationException::withMessages(['coupon' => $exception->getMessage()]);
            }
        }

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

    /**
     * The coupon held for this checkout, validated against this purchase.
     *
     * Null when no code is held. A refused code still comes back as an outcome
     * so the screen can say **why** — "that code ended on 30 June" and "that
     * code is for the Enterprise package" are different problems with different
     * next steps.
     */
    protected function couponOutcome(
        Request $request,
        BusinessAccount $account,
        Package $package,
        CalculateActivationQuote $quotes,
    ): ?CouponOutcome {
        $code = $request->session()->get(self::COUPON_SESSION_KEY);

        if (! is_string($code) || trim($code) === '') {
            return null;
        }

        // The undiscounted fees, which is what a coupon is worked out against.
        $base = $quotes->handle($package, account: $account);

        return $this->coupons->validate(
            code: $code,
            account: $account,
            package: $package,
            registrationFee: $base->amountFor(AllocationType::RegistrationFee),
            packageFee: $base->amountFor(AllocationType::PackageFee),
        );
    }

    /**
     * The quote, with the discount applied only if the coupon stands.
     */
    protected function quoteFor(
        CalculateActivationQuote $quotes,
        Package $package,
        BusinessAccount $account,
        ?CouponOutcome $coupon,
    ): ActivationQuote {
        return $quotes->handle(
            $package,
            discount: $coupon?->isAccepted === true ? $coupon->discount : null,
            discountDescription: $coupon?->isAccepted === true
                ? (string) __('billing.coupons.line', ['code' => (string) $coupon->coupon?->code])
                : null,
            account: $account,
        );
    }

    protected function pendingSubscription(BusinessAccount $account): ?UserPackage
    {
        return $account->packages()
            ->where('status', UserPackageStatus::PendingPayment)
            ->with('package.features')
            ->first();
    }
}
