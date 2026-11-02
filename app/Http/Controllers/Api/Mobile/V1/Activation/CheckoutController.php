<?php

namespace App\Http\Controllers\Api\Mobile\V1\Activation;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\Actions\CalculateActivationQuote;
use App\Domain\Billing\Actions\ExpireUnpaidPayments;
use App\Domain\Billing\Actions\RecordPaymentFromQuote;
use App\Domain\Billing\Actions\ReserveCoupon;
use App\Domain\Billing\Actions\StartGatewayPayment;
use App\Domain\Billing\CouponValidator;
use App\Domain\Billing\Data\ActivationQuote;
use App\Domain\Billing\Data\CouponOutcome;
use App\Domain\Billing\Enums\AllocationType;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\PaymentDeadline;
use App\Domain\Package\Enums\UserPackageStatus;
use App\Domain\Package\Models\Package;
use App\Domain\Package\Models\UserPackage;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Requests\Mobile\Activation\CheckoutCouponRequest;
use App\Http\Requests\Mobile\Activation\PayCheckoutRequest;
use App\Http\Resources\Mobile\Activation\CheckoutResource;
use App\Integrations\Payment\PaymentGatewayManager;
use App\Support\Money\Currency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The combined registration and package fee checkout (§9). Open before
 * activation (§5.4).
 *
 * Stateless: the web holds a coupon in the session, the app has none, so the
 * coupon code travels with each request. It is re-validated on the server every
 * time. The breakdown is recalculated on every view and again at payment, and
 * nothing the app sends decides what is charged (§36.1).
 */
class CheckoutController extends MobileController
{
    use ResolvesBusinessAccount;

    public function __construct(
        protected CouponValidator $coupons,
        protected ReserveCoupon $reserveCoupon,
        protected ExpireUnpaidPayments $expiries,
        protected PaymentDeadline $deadline,
        protected CalculateActivationQuote $quotes,
        protected PaymentGatewayManager $gateways,
    ) {}

    /**
     * Checkout
     *
     * The breakdown for the package the account has chosen: every line, the total, the
     * gateways available and how long the attempt stays open. Pass `coupon` to see the
     * total with that code applied (nothing is spent by looking). `409` with
     * `code: no_package_selected` when no package has been chosen yet.
     *
     * @response CheckoutResource
     */
    public function show(Request $request): CheckoutResource|JsonResponse
    {
        $request->validate(['coupon' => ['nullable', 'string', 'max:40']]);

        return $this->summary($request, $request->query('coupon'));
    }

    /**
     * Try a coupon
     *
     * Works the checkout out again with the given code and says whether it is accepted, and
     * if not why. Nothing is stored or reserved: send the same code in `POST /checkout` to
     * pay with it. An empty code returns the checkout without one.
     *
     * @response CheckoutResource
     */
    public function coupon(CheckoutCouponRequest $request): CheckoutResource|JsonResponse
    {
        return $this->summary($request, $request->validated('code'));
    }

    /**
     * Pay
     *
     * Records the payment from a freshly worked-out quote — the same Action the web uses, so a
     * double submit reuses one payment — and opens the gateway. Answers with `redirect_url`: open
     * it in a browser view; the payment is settled by the gateway's notification, so poll
     * `GET /checkout/payment` afterwards. `409 no_package_selected` when nothing is chosen;
     * `422` on `gateway` when the gateway is unavailable, a payment is being reconciled, or there
     * is nothing to pay; `422` on `coupon` when its last slot went.
     *
     * @response array{redirect_url: string, payment_id: string}
     */
    public function pay(PayCheckoutRequest $request, RecordPaymentFromQuote $record, StartGatewayPayment $start): JsonResponse
    {
        $account = $this->businessAccountFor($request);
        $gateway = (string) $request->validated('gateway');

        $subscription = $this->pendingSubscription($account);
        $package = $subscription?->package;

        if ($subscription === null || $package === null) {
            return $this->noPackage();
        }

        $coupon = $this->couponOutcome($request->validated('coupon'), $account, $package);
        $quote = $this->quoteFor($package, $account, $coupon);

        // Close anything overdue before recording (§9).
        $this->expiries->forPayable($subscription);

        if ($this->deadlineFor($subscription)['reconciling']) {
            throw ValidationException::withMessages(['gateway' => __('payment.checkout.reconciling_body')]);
        }

        if (! $quote->isPayable()) {
            throw ValidationException::withMessages(['gateway' => 'There is nothing to pay for this package.']);
        }

        $payment = $record->handle(
            account: $account,
            quote: $quote,
            purpose: PaymentPurpose::Activation,
            idempotencyKey: 'activation:'.$subscription->public_id,
            payable: $subscription,
        );

        // Held now, not when the page was opened: this is the moment somebody commits to paying.
        if ($coupon !== null && $coupon->isAccepted && $coupon->coupon !== null) {
            try {
                $this->reserveCoupon->handle(
                    $coupon->coupon,
                    $account,
                    $payment,
                    $coupon->discount ?? $quote->amountFor(AllocationType::Discount),
                );
            } catch (RuntimeException $exception) {
                throw ValidationException::withMessages(['coupon' => $exception->getMessage()]);
            }
        }

        return new JsonResponse([
            'redirect_url' => $start->handle($payment, $gateway, $request),
            'payment_id' => $payment->public_id,
        ]);
    }

    protected function summary(Request $request, mixed $code): CheckoutResource|JsonResponse
    {
        $account = $this->businessAccountFor($request);

        $subscription = $this->pendingSubscription($account);
        $package = $subscription?->package;

        if ($subscription === null || $package === null) {
            return $this->noPackage();
        }

        $coupon = $this->couponOutcome($code, $account, $package);
        $quote = $this->quoteFor($package, $account, $coupon);

        // Anything past its deadline is closed before the answer, so the app never offers
        // a "Pay" for a checkout that has run out.
        $this->expiries->forPayable($subscription);

        return new CheckoutResource([
            'package' => $package,
            'quote' => $quote,
            'coupon' => $coupon,
            'deadline' => $this->deadlineFor($subscription),
            'gateways' => array_map(fn (string $name) => [
                'name' => $name,
                'label' => (string) config("payment.gateways.{$name}.label", $name),
                'fx' => $this->gateways->conversionNotice($name, Currency::base(), $quote->total()),
            ], $this->gateways->availableFor(Currency::base())),
        ]);
    }

    protected function noPackage(): JsonResponse
    {
        return new JsonResponse([
            'code' => 'no_package_selected',
            'message' => 'Choose a package to continue.',
        ], 409);
    }

    /**
     * The coupon, validated against this purchase. Null when no code was sent.
     */
    protected function couponOutcome(mixed $code, BusinessAccount $account, Package $package): ?CouponOutcome
    {
        if (! is_string($code) || trim($code) === '') {
            return null;
        }

        $base = $this->quotes->handle($package, account: $account);

        return $this->coupons->validate(
            code: trim($code),
            account: $account,
            package: $package,
            registrationFee: $base->amountFor(AllocationType::RegistrationFee),
            packageFee: $base->amountFor(AllocationType::PackageFee),
        );
    }

    protected function quoteFor(Package $package, BusinessAccount $account, ?CouponOutcome $coupon): ActivationQuote
    {
        return $this->quotes->handle(
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
