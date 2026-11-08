<?php

namespace App\Http\Controllers\Api\Mobile\V1\Packages;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\Actions\CalculateRenewalQuote;
use App\Domain\Billing\Actions\RecordPaymentFromQuote;
use App\Domain\Billing\Actions\StartGatewayPayment;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Package\Actions\OpenRenewal;
use App\Domain\Package\Enums\UserPackageStatus;
use App\Domain\Package\Models\UserPackage;
use App\Domain\Package\Queries\AccountSubscription;
use App\Domain\Package\SubscriptionPolicy;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Requests\Mobile\Packages\PayRenewalRequest;
use App\Http\Resources\Mobile\Packages\SubscriptionResource;
use App\Integrations\Payment\PaymentGatewayManager;
use App\Support\Money\Currency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Renewing the term an account is on (§8.2, §8.4).
 *
 * Self-scoped: the account comes from the signed-in person's membership, never
 * from the URL (§31.3). Open after a term has expired — §8.4 keeps "limited
 * access to Payment and renewal modules", and a renewal that closes at expiry
 * closes exactly when it is needed.
 *
 * The quote is recalculated server-side on every view and again at payment.
 * Nothing about the amount comes from the app (§36.1).
 */
class RenewalController extends MobileController
{
    use ResolvesBusinessAccount;

    public function __construct(
        protected SubscriptionPolicy $policy,
        protected OpenRenewal $open,
        protected CalculateRenewalQuote $quotes,
        protected PaymentGatewayManager $gateways,
    ) {}

    /**
     * Renewal
     *
     * Opens the renewal and shows its terms: the package, validity, when the renewed term starts,
     * the quote and the gateways available. `409` with `code: renewal_unavailable` and a `message`
     * saying why when the term cannot be renewed now.
     *
     * @response array{current: SubscriptionResource|null, renewal: array<string, mixed>, quote: array<string, mixed>, gateways: list<array{name: string, label: string, fx: mixed}>}
     */
    public function show(Request $request, AccountSubscription $subscriptions): JsonResponse
    {
        $account = $this->businessAccountFor($request);
        $current = $this->currentTerm($account);

        $blocker = $this->policy->renewalBlocker($current);

        if ($blocker !== null || $current === null) {
            return $this->unavailable($blocker ?? __('package.renewal.terms_unavailable'));
        }

        // Opening the renewal here rather than at payment is what makes the
        // quote and the record agree: the terms shown are the ones captured.
        $renewal = $this->open->handle($account, $current);
        $terms = $renewal->terms();

        if ($terms === null) {
            return $this->unavailable(__('package.renewal.terms_unavailable'));
        }

        $quote = $this->quotes->handle($terms, account: $account);
        $currentTerm = $subscriptions->current($account);

        return new JsonResponse([
            'current' => $currentTerm === null ? null : new SubscriptionResource($currentTerm),
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
                'label' => (string) config("payment.gateways.{$name}.label", $name),
                'fx' => $this->gateways->conversionNotice($name, Currency::base(), $quote->total()),
            ], $this->gateways->availableFor(Currency::base())),
        ]);
    }

    /**
     * Pay for the renewal
     *
     * Records the renewal payment from a freshly worked-out quote — keyed on the renewal, so a
     * double submit reuses one payment — and opens the gateway. Answers with `redirect_url` to
     * open in a browser view; the payment is settled by the gateway's notification. `409
     * renewal_unavailable` when no renewal is open (call `GET /subscription/renew` first); `422`
     * on `gateway` when it is unavailable or there is nothing to pay.
     *
     * @response array{redirect_url: string, payment_id: string}
     */
    public function pay(PayRenewalRequest $request, RecordPaymentFromQuote $record, StartGatewayPayment $start): JsonResponse
    {
        $account = $this->businessAccountFor($request);

        $renewal = $this->open->pendingRenewalFor($account);
        $terms = $renewal?->terms();

        if ($renewal === null || $terms === null) {
            return $this->unavailable(__('package.renewal.terms_unavailable'));
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

        return new JsonResponse([
            'redirect_url' => $start->handle($payment, (string) $request->validated('gateway'), $request, separateReturns: false),
            'payment_id' => $payment->public_id,
        ]);
    }

    protected function unavailable(string $message): JsonResponse
    {
        return new JsonResponse(['code' => 'renewal_unavailable', 'message' => $message], 409);
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
