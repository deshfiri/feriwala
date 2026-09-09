<?php

namespace App\Http\Controllers\Erp;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\Actions\CalculatePackageChangeQuote;
use App\Domain\Billing\Actions\RecordPaymentFromQuote;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Package\Actions\OpenPackageChange;
use App\Domain\Package\Enums\UserPackageStatus;
use App\Domain\Package\Models\Package;
use App\Domain\Package\Models\UserPackage;
use App\Domain\Package\PackageChangePlanner;
use App\Domain\Package\Queries\AccountHoldings;
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
 * Moving to another package (§8.3).
 *
 * Self-scoped: the account comes from the signed-in person's membership, never
 * from the URL (§31.3). Every figure is worked out server-side by
 * {@see PackageChangePlanner} and recalculated at payment; nothing about an
 * amount or an effective date comes from the browser (§36.1).
 *
 * The list shows what each move would actually do — up or down, what it costs
 * after the credit, when it applies, and what stands in the way — because §8.3
 * makes those the terms of the change and D16 makes the blockers something the
 * account holder is owed rather than a refusal.
 */
class PackageChangeController extends Controller
{
    use ResolvesBusinessAccount;

    public function __construct(
        protected PackageChangePlanner $planner,
        protected OpenPackageChange $open,
        protected CalculatePackageChangeQuote $quotes,
        protected AccountHoldings $holdings,
    ) {}

    public function index(Request $request, PaymentGatewayManager $gateways): Response|RedirectResponse
    {
        $account = $this->businessAccountFor($request);
        $current = $this->currentTerm($account);

        if ($current === null) {
            return to_route('packages.index');
        }

        $counts = $this->holdings->counts($account);

        $options = Package::query()
            ->available()
            ->with(['features', 'charges'])
            ->whereKeyNot($current->package_id)
            ->orderBy('fee_minor')
            ->get()
            ->map(function (Package $package) use ($current, $counts, $account) {
                $plan = $this->planner->plan($current, $package, $counts);

                return array_merge($plan->toArray(), [
                    'slug' => $package->slug,
                    'quote' => $this->quotes->handle($plan, $account)->toArray(),
                ]);
            })
            ->all();

        return Inertia::render('settings/package-change', [
            'current' => [
                'package' => $current->terms()?->name,
                'expires_at' => $current->expires_at?->toIso8601String(),
            ],
            'options' => $options,
            'gateways' => array_map(fn (string $name) => [
                'name' => $name,
                'label' => config("payment.gateways.{$name}.label", $name),
            ], $gateways->available()),
        ]);
    }

    /**
     * Open the change and hand off to the gateway.
     */
    public function store(
        Request $request,
        RecordPaymentFromQuote $record,
        PaymentGatewayManager $gateways,
    ): RedirectResponse {
        $account = $this->businessAccountFor($request);

        $validated = $request->validate([
            'package' => ['required', 'string'],
            'gateway' => ['required', 'string', Rule::in($gateways->available())],
        ]);

        $current = $this->currentTerm($account);
        $target = Package::query()->where('slug', $validated['package'])->first();

        if ($current === null || $target === null) {
            return to_route('subscription.change.index');
        }

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
            // Nothing to pay — a downgrade onto a free plan, or an upgrade the
            // credit covers in full. It still has to be settled deliberately,
            // so it waits for the free-change endpoint rather than silently
            // activating here.
            return to_route('subscription.show')
                ->with('info', __('package.change.nothing_to_pay'));
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
            throw ValidationException::withMessages(['gateway' => $e->getMessage()]);
        }

        if ($payment->canTransitionTo(PaymentStatus::Initiated)) {
            $payment->transitionTo(PaymentStatus::Initiated);
            $payment->forceFill(['initiated_at' => now()])->save();
        }

        return redirect()->away($redirect->url);
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
