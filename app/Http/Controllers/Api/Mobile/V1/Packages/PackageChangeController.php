<?php

namespace App\Http\Controllers\Api\Mobile\V1\Packages;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\Actions\CalculatePackageChangeQuote;
use App\Domain\Billing\Actions\RecordPaymentFromQuote;
use App\Domain\Billing\Actions\StartGatewayPayment;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Package\Actions\OpenPackageChange;
use App\Domain\Package\Enums\UserPackageStatus;
use App\Domain\Package\Models\Package;
use App\Domain\Package\Models\UserPackage;
use App\Domain\Package\PackageChangePlanner;
use App\Domain\Package\Queries\AccountHoldings;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Requests\Mobile\Packages\ChangePackageRequest;
use App\Integrations\Payment\PaymentGatewayManager;
use App\Support\Money\Currency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Moving to another package (§8.3).
 *
 * Self-scoped: the account comes from the signed-in person's membership, never
 * from the URL (§31.3). Every figure is worked out server-side by
 * {@see PackageChangePlanner} and recalculated at payment; nothing about an
 * amount or an effective date comes from the app (§36.1).
 *
 * The list shows what each move would actually do — up or down, what it costs
 * after the credit, when it applies, and what stands in the way — because §8.3
 * makes those the terms of the change and D16 makes the blockers something the
 * account holder is owed rather than a refusal.
 */
class PackageChangeController extends MobileController
{
    use ResolvesBusinessAccount;

    public function __construct(
        protected PackageChangePlanner $planner,
        protected OpenPackageChange $open,
        protected CalculatePackageChangeQuote $quotes,
        protected AccountHoldings $holdings,
        protected PaymentGatewayManager $gateways,
    ) {}

    /**
     * Package change options
     *
     * Every other available package with what moving to it would do: direction, the quote after
     * credit, when it applies and anything blocking it. `409` with `code: no_current_package`
     * when the account has no running term to move away from.
     *
     * @response array{current: array{package: string|null, expires_at: string|null}, options: list<array<string, mixed>>, gateways: list<array{name: string, label: string, fx: mixed}>}
     */
    public function index(Request $request): JsonResponse
    {
        $account = $this->businessAccountFor($request);
        $current = $this->currentTerm($account);

        if ($current === null) {
            return $this->noCurrentPackage();
        }

        $counts = $this->holdings->counts($account);

        $options = Package::query()
            ->available()
            ->with(['features', 'charges'])
            ->whereKeyNot($current->package_id)
            ->orderBy('fee')
            ->get()
            ->map(function (Package $package) use ($current, $counts, $account) {
                $plan = $this->planner->plan($current, $package, $counts);

                return array_merge($plan->toArray(), [
                    'slug' => $package->slug,
                    'quote' => $this->quotes->handle($plan, $account)->toArray(),
                ]);
            })
            ->all();

        return new JsonResponse([
            'current' => [
                'package' => $current->terms()?->name,
                'expires_at' => $current->expires_at?->toIso8601String(),
            ],
            'options' => $options,
            'gateways' => array_map(fn (string $name) => [
                'name' => $name,
                'label' => (string) config("payment.gateways.{$name}.label", $name),
                'fx' => $this->gateways->conversionNotice($name, Currency::base()),
            ], $this->gateways->availableFor(Currency::base())),
        ]);
    }

    /**
     * Change package
     *
     * Opens the change to the package `package` (a slug from `GET /subscription/change`) and
     * opens the gateway. Answers with `redirect_url` to open in a browser view; the payment is
     * settled by the gateway's notification. When there is nothing to pay (a credit covers it
     * in full) `redirect_url` is `null` and `message` says so. `409 no_current_package` without
     * a running term; `404` for an unknown package; `422` when the move is blocked (the message
     * says what stands in the way) or the gateway is unavailable.
     *
     * @response array{redirect_url: string|null, payment_id: string|null, message?: string}
     */
    public function store(ChangePackageRequest $request, RecordPaymentFromQuote $record, StartGatewayPayment $start): JsonResponse
    {
        $account = $this->businessAccountFor($request);

        $gateway = (string) $request->validated('gateway');

        $current = $this->currentTerm($account);

        if ($current === null) {
            return $this->noCurrentPackage();
        }

        $target = Package::query()->where('slug', $request->validated('package'))->firstOrFail();

        try {
            $change = $this->open->handle($account, $current, $target, $this->holdings->counts($account));
        } catch (RuntimeException $exception) {
            // The guard's refusal is a legitimate answer to a request, not an
            // error page: D16 wants the account holder told what is in the way.
            throw ValidationException::withMessages(['package' => $exception->getMessage()]);
        }

        // Recalculated from the stored plan rather than trusting the request.
        $plan = $this->planner->plan($current, $target, $this->holdings->counts($account));
        $quote = $this->quotes->handle($plan, $account);

        if (! $quote->isPayable()) {
            // Nothing to pay: it is settled deliberately, not silently activated here.
            return new JsonResponse([
                'redirect_url' => null,
                'payment_id' => null,
                'message' => __('package.change.nothing_to_pay'),
            ]);
        }

        $payment = $record->handle(
            account: $account,
            quote: $quote,
            purpose: $plan->isUpgrade()
                ? PaymentPurpose::PackageUpgrade
                : PaymentPurpose::PackageDowngrade,
            idempotencyKey: 'package-change:'.$change->public_id,
            payable: $change,
        );

        return new JsonResponse([
            'redirect_url' => $start->handle($payment, $gateway, $request, separateReturns: false),
            'payment_id' => $payment->public_id,
        ]);
    }

    protected function noCurrentPackage(): JsonResponse
    {
        return new JsonResponse([
            'code' => 'no_current_package',
            'message' => __('package.change.no_current_package'),
        ], 409);
    }

    /**
     * The term a change would move away from.
     */
    protected function currentTerm(BusinessAccount $account): ?UserPackage
    {
        $pointed = $account->currentPackage()->with('package')->first();

        if ($pointed !== null && in_array($pointed->status, UserPackageStatus::entitling(), true)) {
            return $pointed;
        }

        return $account->packages()
            ->with('package')
            ->whereIn('status', UserPackageStatus::entitling())
            ->latest('id')
            ->first();
    }
}
