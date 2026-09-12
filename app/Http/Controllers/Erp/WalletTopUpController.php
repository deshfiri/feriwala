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
use App\Integrations\Payment\PaymentGatewayManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

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
    public function store(Request $request, PaymentGatewayManager $gateways): RedirectResponse
    {
        $account = $this->businessAccountFor($request);
        $wallet = $this->walletFor($account);

        $validated = $request->validate([
            // Minor units, like every other money field in the application.
            'amount_minor' => ['required', 'integer', 'min:1'],
            'gateway' => ['required', 'string', Rule::in($gateways->available())],
        ]);

        // Throws a validation error rather than a refusal page: being asked for
        // a bigger amount is an answer to the form, not a failure.
        $plan = $this->planner->handle($wallet, (int) $validated['amount_minor']);

        $payment = $this->payments->handle($account, $plan);

        $payment->forceFill(['gateway' => $validated['gateway']])->save();

        try {
            $redirect = $gateways->driver($validated['gateway'])->initiate(
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

        return redirect()->away($redirect->url);
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
