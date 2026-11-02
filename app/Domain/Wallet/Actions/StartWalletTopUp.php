<?php

namespace App\Domain\Wallet\Actions;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\Actions\RecordPaymentLog;
use App\Domain\Billing\Actions\SettlePayment;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\PaymentLog;
use App\Domain\Wallet\Data\TopUpPlan;
use App\Domain\Wallet\Models\Wallet;
use App\Integrations\Payment\Data\PaymentIntent;
use App\Integrations\Payment\Exceptions\GatewayUnavailable;
use App\Integrations\Payment\GatewayNavigation;
use App\Integrations\Payment\Gateways\GatewayCredentials;
use App\Integrations\Payment\PaymentGatewayManager;
use App\Support\Money\Money;
use Illuminate\Validation\ValidationException;

/**
 * Plan a wallet top-up, record its payment and open the gateway session, for a
 * client that cannot follow a browser redirect itself (the mobile API).
 *
 * The same steps `Erp\WalletTopUpController::store()` takes, in the same order,
 * through the same {@see PlanWalletTopUp} and {@see RecordTopUpPayment}; only
 * the last step differs, in that the address to open is returned rather than
 * navigated to. Settlement is untouched: {@see SettlePayment}
 * remains the only thing that decides a payment was made.
 */
class StartWalletTopUp
{
    public function __construct(
        protected PlanWalletTopUp $planner,
        protected RecordTopUpPayment $payments,
        protected RecordPaymentLog $paymentLogs,
        protected PaymentGatewayManager $gateways,
    ) {}

    /**
     * @return array{payment: Payment, plan: TopUpPlan, redirect_url: string}
     *
     * @throws ValidationException when the amount is not payable or the gateway is unavailable
     */
    public function handle(BusinessAccount $account, Wallet $wallet, Money $amount, string $gateway): array
    {
        $plan = $this->planner->handle($wallet, $amount);

        $payment = $this->payments->handle($account, $plan);

        $driver = $this->gateways->driver($gateway);

        $payment->forceFill([
            'gateway' => $gateway,
            // Stamped now and never recalculated (§26.4).
            'gateway_mode' => $driver->isSandbox()
                ? GatewayCredentials::SANDBOX
                : GatewayCredentials::LIVE,
        ])->save();

        try {
            $redirect = $driver->initiate(
                PaymentIntent::forPayment(
                    $payment,
                    successUrl: route('checkout.return'),
                    failUrl: route('checkout.failed'),
                    cancelUrl: route('checkout.cancelled'),
                    ipnUrl: route('webhooks.payment', $gateway),
                ),
            );

            GatewayNavigation::ensureOpenable($redirect->url, $gateway);
        } catch (GatewayUnavailable $exception) {
            $this->paymentLogs->handle(
                gateway: $gateway,
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

        return ['payment' => $payment, 'plan' => $plan, 'redirect_url' => $redirect->url];
    }
}
