<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Billing\Actions\ConfigureExchangeRates;
use App\Domain\Billing\Actions\ConfigureGateway;
use App\Domain\Billing\Actions\TestGatewayConnection;
use App\Domain\Billing\ExchangeRates;
use App\Domain\Billing\Models\PaymentLog;
use App\Domain\Billing\Policies\BillingSettingsPolicy;
use App\Domain\Billing\Queries\GatewayPaymentTotals;
use App\Http\Controllers\Controller;
use App\Integrations\Payment\Contracts\TestsConnection;
use App\Integrations\Payment\Data\GatewayOutcome;
use App\Integrations\Payment\Exceptions\GatewayUnavailable;
use App\Integrations\Payment\PaymentGatewayManager;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;

/**
 * The payment gateways, and what each one still needs (§26.4, D7).
 *
 * All eight §26 names are listed rather than only the working ones. An
 * administrator asking "why can nobody pay by bKash" needs to see that it exists
 * and what is missing, not an empty list — and "enabled but not credentialled"
 * is a state worth being able to see before a customer finds it at checkout.
 *
 * **No credential is ever sent to the browser.** The screen reports whether a
 * credential is present and the *names* of the ones that are not; the value
 * itself only travels inbound. A secret in an Inertia prop is a secret in the
 * page source and in every error report that captures it (§42).
 */
class PaymentGatewayController extends Controller
{
    public function __construct(
        protected ConfigureGateway $configure,
    ) {}

    public function index(Request $request, PaymentGatewayManager $gateways): Response
    {
        $actor = $this->actor($request);

        abort_unless(BillingSettingsPolicy::canView($actor), 403);

        return Inertia::render('admin/gateways', [
            'gateways' => $this->catalogue($gateways),
            'modes' => ConfigureGateway::MODES,
            'can' => ['manage' => BillingSettingsPolicy::canManageGateways($actor)],
        ]);
    }

    /**
     * Every gateway on one list, each with its own on/off switch.
     *
     * The same catalogue as {@see index()}, without the credential forms: the
     * screen somebody opens to decide which providers customers can pay with
     * today. The switches post to {@see toggle()}, so the rules for switching
     * a gateway on — credentials present, able to verify — are the server's,
     * not this page's.
     */
    public function switches(Request $request, PaymentGatewayManager $gateways, GatewayPaymentTotals $totals): Response
    {
        $actor = $this->actor($request);

        abort_unless(BillingSettingsPolicy::canView($actor), 403);

        $catalogue = $this->catalogue($gateways);

        /** @var list<string> $names */
        $names = array_map(fn (array $entry) => (string) $entry['name'], $catalogue);
        $received = $totals->handle($names);

        return Inertia::render('admin/payment-switches', [
            /*
             * Each gateway carries what it has taken beside whether it is on,
             * so the decision to switch one off is made knowing how much money
             * goes through it.
             */
            'gateways' => array_map(fn (array $entry) => [
                ...$entry,
                ...$received['by_gateway'][(string) $entry['name']],
            ], $catalogue),
            'summary' => [
                'received' => $received['received'],
                'payments' => $received['payments'],
                'taking_payments' => count(array_filter($catalogue, fn (array $entry) => $entry['is_available'] === true)),
            ],
            'can' => ['manage' => BillingSettingsPolicy::canManageGateways($actor)],
        ]);
    }

    /**
     * The rates at which Taka is taken through international gateways (D4,
     * amended).
     *
     * Shown with what each rate does: which gateways it makes available for a
     * Taka payment, and what ৳1,000 comes to — worked out here, so the
     * administrator checks a figure the server will actually use.
     */
    public function exchangeRates(Request $request, PaymentGatewayManager $gateways, ExchangeRates $rates): Response
    {
        $actor = $this->actor($request);

        abort_unless(BillingSettingsPolicy::canView($actor), 403);

        $sample = Money::fromDecimal('1000', Currency::base());

        $currencies = array_map(function (Currency $currency) use ($rates, $sample): array {
            $rate = $rates->rateFor($currency);

            return [
                'currency' => $currency->value,
                'rate' => $rate,
                'sample' => $rate === null ? null : $rates->chargeFor($sample, $currency, $rate)->jsonSerialize(),
            ];
        }, ExchangeRates::convertible());

        // Gateways that cannot take Taka themselves: the ones a rate matters to.
        $dependent = [];

        foreach ($gateways->implemented() as $name) {
            $supported = $gateways->driver($name)->supportedCurrencies();

            if (in_array(Currency::base(), $supported, true)) {
                continue;
            }

            $dependent[] = [
                'name' => $name,
                'label' => (string) config("payment.gateways.{$name}.label", $name),
                'currencies' => array_map(fn (Currency $currency) => $currency->value, $supported),
                'is_available' => in_array($name, $gateways->available(), true),
                'offered_for_taka' => in_array($name, $gateways->availableFor(Currency::base()), true),
                'charge_currency' => $gateways->chargeCurrencyFor($name, Currency::base())?->value,
            ];
        }

        return Inertia::render('admin/exchange-rates', [
            'rates' => $currencies,
            'gateways' => $dependent,
            'sample' => $sample->jsonSerialize(),
            'can' => ['manage' => BillingSettingsPolicy::canManageGateways($actor)],
        ]);
    }

    public function updateExchangeRates(Request $request, ConfigureExchangeRates $configure): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(BillingSettingsPolicy::canManageGateways($actor), 403);

        $codes = array_map(fn (Currency $currency) => $currency->value, ExchangeRates::convertible());

        $validated = $request->validate([
            'rates' => ['required', 'array'],
            'rates.*' => ['nullable', 'string', 'max:20'],
        ]);

        /** @var array<string, string|null> $rates */
        $rates = $validated['rates'];

        // Unknown currencies are not stored and not ignored: say so.
        foreach (array_keys($rates) as $code) {
            if (! in_array($code, $codes, true)) {
                throw ValidationException::withMessages(["rates.{$code}" => __('gateways.rates.unknown_currency')]);
            }
        }

        try {
            $configure->handle($actor, $rates);
        } catch (InvalidArgumentException $exception) {
            $failing = array_find(
                $codes,
                fn (string $code) => str_contains($exception->getMessage(), "[{$code}]") || str_contains($exception->getMessage(), "for {$code} "),
            );

            throw ValidationException::withMessages([
                $failing !== null ? "rates.{$failing}" : 'rates' => $exception->getMessage(),
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('gateways.rates.saved')]);

        return back();
    }

    /**
     * Store credentials for one gateway and one mode.
     */
    public function update(Request $request, PaymentGatewayManager $gateways): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(BillingSettingsPolicy::canManageGateways($actor), 403);

        $validated = $request->validate([
            // Only gateways with a driver can be configured. The rest have
            // nothing to hold credentials for yet.
            'gateway' => ['required', Rule::in($gateways->implemented())],
            'mode' => ['required', Rule::in(ConfigureGateway::MODES)],
            'credentials' => ['array'],
            'credentials.*' => ['nullable', 'string', 'max:1000'],
        ]);

        /** @var array<string, string|null> $credentials */
        $credentials = $validated['credentials'] ?? [];

        try {
            $this->configure->handle($actor, $validated['gateway'], $validated['mode'], $credentials);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['gateway' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('gateways.saved')]);

        return back();
    }

    /**
     * Switch one gateway on or off (§26.4).
     *
     * Separate from storing credentials on purpose: entering a store password is
     * preparation, but switching a gateway on is the moment real customers can
     * be sent to it.
     */
    public function toggle(Request $request, PaymentGatewayManager $gateways): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(BillingSettingsPolicy::canManageGateways($actor), 403);

        $validated = $request->validate([
            'gateway' => ['required', Rule::in($gateways->implemented())],
            'enabled' => ['required', 'boolean'],
        ]);

        try {
            $this->configure->setEnabled($actor, $validated['gateway'], (bool) $validated['enabled']);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            // A refusal to switch on is an answer to the form, not a failure
            // page: it says which credential is still missing.
            throw ValidationException::withMessages(['enabled' => $exception->getMessage()]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __($validated['enabled'] ? 'gateways.enabled' : 'gateways.disabled'),
        ]);

        return back();
    }

    /**
     * A real authentication call with the stored credentials.
     *
     * Answers only "worked" or the provider's own safe error — the token the
     * call obtains never leaves the server.
     */
    public function testConnection(Request $request, PaymentGatewayManager $gateways, TestGatewayConnection $test): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(BillingSettingsPolicy::canManageGateways($actor), 403);

        $validated = $request->validate([
            'gateway' => ['required', Rule::in($gateways->implemented())],
        ]);

        try {
            $test->handle($actor, $validated['gateway']);
        } catch (InvalidArgumentException|GatewayUnavailable $exception) {
            throw ValidationException::withMessages(['connection' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('gateways.connection.ok')]);

        return back();
    }

    /**
     * Every configured gateway, with when it last confirmed a payment.
     *
     * @return list<array<string, mixed>>
     */
    protected function catalogue(PaymentGatewayManager $gateways): array
    {
        $verified = $this->lastVerifications();

        return array_values(array_map(
            fn (array $entry) => [
                ...$entry,

                /*
                 * When this provider last confirmed a payment with us (§26.4).
                 *
                 * Read from the payment log rather than stored as a setting,
                 * because it is a fact about what happened rather than something
                 * anybody configured — and because a "last verified" field that
                 * a screen writes is a field that can be wrong.
                 */
                'last_verified_at' => $verified[$entry['name']] ?? null,

                'can_test_connection' => $gateways->isImplemented((string) $entry['name'])
                    && $gateways->driver((string) $entry['name']) instanceof TestsConnection,
            ],
            $gateways->catalogue(),
        ));
    }

    /**
     * The last time each provider confirmed a payment, server-to-server.
     *
     * Only a verification counts. An initiation proves a session could be
     * created, which is not the same as the provider having answered the only
     * question that releases value.
     *
     * @return array<string, string>
     */
    protected function lastVerifications(): array
    {
        /** @var array<int, object{gateway: string, last_verified_at: string|null}> $rows */
        $rows = PaymentLog::query()
            ->where('event', 'verify')
            ->where('outcome', GatewayOutcome::Paid->value)
            ->toBase()
            ->select('gateway')
            ->selectRaw('max(created_at) as last_verified_at')
            ->groupBy('gateway')
            ->get()
            ->all();

        $verified = [];

        foreach ($rows as $row) {
            if ($row->last_verified_at !== null) {
                $verified[$row->gateway] = (string) $row->last_verified_at;
            }
        }

        return $verified;
    }

    protected function actor(Request $request): User
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);

        return $actor;
    }
}
