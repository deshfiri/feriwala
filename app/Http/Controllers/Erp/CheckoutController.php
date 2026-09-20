<?php

namespace App\Http\Controllers\Erp;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\Actions\CalculateActivationQuote;
use App\Domain\Billing\Actions\ExpireUnpaidPayments;
use App\Domain\Billing\Actions\RecordPaymentFromQuote;
use App\Domain\Billing\Actions\RecordPaymentLog;
use App\Domain\Billing\Actions\ReserveCoupon;
use App\Domain\Billing\CouponValidator;
use App\Domain\Billing\Data\ActivationQuote;
use App\Domain\Billing\Data\CouponOutcome;
use App\Domain\Billing\Enums\AllocationType;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\PaymentLog;
use App\Domain\Billing\PaymentDeadline;
use App\Domain\Package\Enums\UserPackageStatus;
use App\Domain\Package\Models\Package;
use App\Domain\Package\Models\UserPackage;
use App\Http\Controllers\Controller;
use App\Integrations\Payment\Data\PaymentIntent;
use App\Integrations\Payment\Exceptions\GatewayUnavailable;
use App\Integrations\Payment\GatewayNavigation;
use App\Integrations\Payment\Gateways\GatewayCredentials;
use App\Integrations\Payment\PaymentGatewayManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

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
        protected ExpireUnpaidPayments $expiries,
        protected PaymentDeadline $deadline,
        protected RecordPaymentLog $paymentLogs,
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

        // Anything past its deadline is closed before the page is drawn, so the
        // screen never offers a "Pay" button for a checkout that has run out.
        $this->expiries->forPayable($subscription);

        return Inertia::render('onboarding/checkout', [
            'package' => [
                'slug' => $package->slug,
                'name' => $package->name,
                'validity_days' => $package->validity_days,
            ],
            'quote' => $quote->toArray(),
            'coupon' => $coupon?->toArray(),
            'deadline' => $this->deadlineFor($subscription),
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
    ): SymfonyResponse {
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

        /*
         * Close anything overdue before recording (§9). The idempotency key
         * below would otherwise hand back the dead attempt — with the total it
         * was quoted at, which may no longer be the price.
         */
        $this->expiries->forPayable($subscription);

        /*
         * A payment for this order is already being reconciled: money arrived
         * after the checkout closed and somebody is sorting it out (§26.4).
         * Taking another would be taking it twice, and the screen already says
         * so — this is the same refusal on the server, where it counts.
         */
        if ($this->deadlineFor($subscription)['reconciling']) {
            throw ValidationException::withMessages([
                'gateway' => __('payment.checkout.reconciling_body'),
            ]);
        }

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

        $driver = $gateways->driver($validated['gateway']);

        $payment->forceFill([
            'gateway' => $validated['gateway'],
            // Stamped now and never recalculated. Sandbox and live is a setting
            // somebody can change, and a payment that re-read it later would
            // start claiming it was taken in a mode it never touched (§26.4).
            'gateway_mode' => $driver->isSandbox()
                ? GatewayCredentials::SANDBOX
                : GatewayCredentials::LIVE,
        ])->save();

        try {
            $redirect = $driver->initiate(
                PaymentIntent::forPayment(
                    $payment,
                    // Three distinct URLs, so the gateway tells us which of the
                    // three happened rather than us reading it out of a field
                    // the browser could have edited (§26.4).
                    successUrl: route('checkout.return'),
                    failUrl: route('checkout.failed'),
                    cancelUrl: route('checkout.cancelled'),
                    ipnUrl: route('webhooks.payment', $validated['gateway']),
                ),
            );

            // An address nobody can be sent to is a session that did not open.
            GatewayNavigation::ensureOpenable($redirect->url, $validated['gateway']);
        } catch (GatewayUnavailable $e) {
            $this->paymentLogs->handle(
                gateway: $validated['gateway'],
                direction: PaymentLog::OUTBOUND,
                event: 'initiate',
                payment: $payment,
                amount: $payment->amount_minor,
                outcome: 'unavailable',
                context: ['error' => $e->getMessage()],
                request: $request,
            );

            // The payment row stays as a draft — the user can try again, or a
            // different gateway, without losing the quote.
            throw ValidationException::withMessages(['gateway' => $e->getMessage()]);
        }

        $this->paymentLogs->handle(
            gateway: $validated['gateway'],
            direction: PaymentLog::OUTBOUND,
            event: 'initiate',
            payment: $payment,
            gatewayReference: $redirect->gatewayReference,
            amount: $payment->amount_minor,
            outcome: 'session_created',
            request: $request,
        );

        if ($payment->canTransitionTo(PaymentStatus::Initiated)) {
            $payment->transitionTo(PaymentStatus::Initiated);
            $payment->forceFill(['initiated_at' => now()])->save();
        }

        // Another origin: the browser goes there itself (§26.4).
        return GatewayNavigation::to($redirect->url, $validated['gateway']);
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

    /**
     * Where the last attempt at paying for this got to.
     *
     * Three separate facts, and each is what somebody needs at a different
     * moment. "You have until Friday" while the window is open. "The last
     * attempt expired" when they come back to a page that looks the same as it
     * did before but is not. And "money arrived after it expired, do not pay
     * again" — the one state where inviting another attempt would take
     * somebody's money twice (§26.4).
     *
     * @return array{hours: int|null, expires_at: string|null, expired: bool, reconciling: bool}
     */
    protected function deadlineFor(UserPackage $subscription): array
    {
        $latest = Payment::query()
            ->where('payable_type', $subscription->getMorphClass())
            ->where('payable_id', $subscription->getKey())
            ->latest('id')
            ->first();

        $isOpen = $latest !== null
            && in_array($latest->status, ExpireUnpaidPayments::EXPIRABLE, true);

        return [
            'hours' => $this->deadline->hours(),
            'expires_at' => $isOpen ? $latest->expires_at?->toIso8601String() : null,
            'expired' => $latest !== null
                && $latest->status === PaymentStatus::Cancelled
                && $latest->expires_at !== null,
            'reconciling' => $latest !== null && $latest->needsReconciliation(),
        ];
    }

    protected function pendingSubscription(BusinessAccount $account): ?UserPackage
    {
        return $account->packages()
            ->where('status', UserPackageStatus::PendingPayment)
            ->with('package.features')
            ->first();
    }
}
