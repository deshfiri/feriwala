<?php

namespace App\Http\Controllers\Erp;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\Actions\RecordPaymentLog;
use App\Domain\Billing\Actions\SettlePayment;
use App\Domain\Billing\Models\PaymentLog;
use App\Domain\Wallet\Actions\PlanWalletTopUp;
use App\Domain\Wallet\Actions\RecordTopUpPayment;
use App\Domain\Wallet\Models\Wallet;
use App\Http\Controllers\Controller;
use App\Integrations\Payment\Data\PaymentIntent;
use App\Integrations\Payment\Exceptions\GatewayUnavailable;
use App\Integrations\Payment\GatewayNavigation;
use App\Integrations\Payment\Gateways\GatewayCredentials;
use App\Integrations\Payment\PaymentGatewayManager;
use App\Support\Money\DecimalAmount;
use App\Support\Money\Rules\DecimalAmountRule;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Putting money into your own wallet (§24, §26.3, P2-19).
 *
 * Self-scoped like the rest of the wallet: the account comes from the
 * membership, so there is no identifier in any of these URLs to change (§31.3).
 *
 * The server decides everything that matters. The browser sends one number — how
 * much — and every other figure, including what that money would do and which
 * purpose it belongs to, is worked out here from the wallet's captured
 * obligation and sent back already decided (§36.1). A total calculated in a
 * browser is a total the ledger cannot vouch for.
 *
 * Settlement is not here and must not be: {@see SettlePayment}
 * is the only thing that decides a payment was made, and a top-up goes through
 * it exactly as an activation does.
 */
class WalletTopUpController extends Controller
{
    use ResolvesBusinessAccount;

    public function __construct(
        protected PlanWalletTopUp $planner,
        protected RecordTopUpPayment $payments,
        protected RecordPaymentLog $paymentLogs,
    ) {}

    /**
     * The top-up screen: what is required, and what a payment would do.
     */
    public function create(Request $request, PaymentGatewayManager $gateways): Response
    {
        $account = $this->businessAccountFor($request);
        $wallet = $this->walletFor($account);

        return Inertia::render('wallet/top-up', [
            'balances' => $wallet->toBalances(),
            'minimum' => $this->planner->minimumFor($wallet)->jsonSerialize(),

            /*
             * Suggested, not imposed. The shortfall is what the account needs to
             * get back to where it should be; anything above it is theirs to
             * decide.
             */
            'suggested' => $wallet->obligationShortfall()->jsonSerialize(),

            'gateways' => array_map(fn (string $gateway) => [
                'value' => $gateway,
                'label' => $gateway,
            ], $gateways->available()),
        ]);
    }

    /**
     * Take the amount, decide what it does, and hand off to the gateway.
     */
    public function store(Request $request, PaymentGatewayManager $gateways): SymfonyResponse
    {
        $account = $this->businessAccountFor($request);
        $wallet = $this->walletFor($account);

        $validated = $request->validate([
            // Decimal Taka, like every other human-facing money field (§36.1)
            // — never minor units. `DecimalAmountRule` refuses anything that
            // is not an exact whole-or-two-decimal-place amount.
            'amount' => ['required', new DecimalAmountRule],
            'gateway' => ['required', 'string', Rule::in($gateways->available())],
        ]);

        $amount = DecimalAmount::parse($validated['amount']);

        // Throws a validation error rather than a refusal page: being asked for
        // a bigger amount is an answer to the form, not a failure. The planner
        // is a domain action that knows nothing of HTTP field names and
        // reports against its own `amount_minor` parameter; remapped here to
        // the `amount` field this form actually submits.
        try {
            $plan = $this->planner->handle($wallet, $amount->minorUnits);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages([
                'amount' => $exception->errors()['amount_minor'] ?? $exception->getMessage(),
            ]);
        }

        $payment = $this->payments->handle($account, $plan);

        $driver = $gateways->driver($validated['gateway']);

        $payment->forceFill([
            'gateway' => $validated['gateway'],
            // Stamped now and never recalculated, so switching the gateway to
            // live later cannot reinterpret this top-up as real money (§26.4).
            'gateway_mode' => $driver->isSandbox()
                ? GatewayCredentials::SANDBOX
                : GatewayCredentials::LIVE,
        ])->save();

        try {
            $redirect = $driver->initiate(
                PaymentIntent::forPayment(
                    $payment,
                    // The same three endpoints the checkout uses: the gateway
                    // says which of the three happened rather than us reading it
                    // out of something the browser could have edited (§26.4).
                    successUrl: route('checkout.return'),
                    failUrl: route('checkout.failed'),
                    cancelUrl: route('checkout.cancelled'),
                    ipnUrl: route('webhooks.payment', $validated['gateway']),
                ),
            );

            // An address nobody can be sent to is a session that did not open.
            GatewayNavigation::ensureOpenable($redirect->url, $validated['gateway']);
        } catch (GatewayUnavailable $exception) {
            $this->paymentLogs->handle(
                gateway: $validated['gateway'],
                direction: PaymentLog::OUTBOUND,
                event: 'initiate',
                payment: $payment,
                outcome: 'unavailable',
                context: ['error' => $exception->getMessage()],
            );

            throw ValidationException::withMessages([
                'gateway' => __('wallet.top_up.errors.gateway_unavailable'),
            ]);
        }

        // Another origin: the browser goes there itself (§26.4).
        return GatewayNavigation::to($redirect->url, $validated['gateway']);
    }

    /**
     * The account's wallet.
     */
    protected function walletFor(BusinessAccount $account): Wallet
    {
        /** @var Wallet $wallet */
        $wallet = Wallet::query()
            ->where('business_account_id', $account->id)
            ->firstOrFail();

        return $wallet;
    }
}
