<?php

namespace App\Domain\Order\Actions;

use App\Domain\Billing\Actions\RecordPaymentLog;
use App\Domain\Billing\Actions\SettlePayment;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\PaymentLog;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Exceptions\OrderRefused;
use App\Domain\Order\Models\Order;
use App\Domain\Wallet\WalletPaymentMethod;
use App\Integrations\Payment\Data\GatewayCapability;
use App\Integrations\Payment\Exceptions\GatewayUnavailable;
use App\Integrations\Payment\Gateways\GatewayCredentials;
use App\Integrations\Payment\PaymentGatewayManager;
use App\Support\Concurrency\DistributedLock;
use App\Support\Concurrency\Exceptions\LockTimeout;
use Illuminate\Http\Request;
use LogicException;

/**
 * Pay an unpaid order with a different gateway than it started with.
 *
 * A payment that failed, was abandoned or whose gateway is down must not trap
 * the order: while it still waits for payment, with its window open and nothing
 * being confirmed, the buyer may pick another available method. The amount,
 * reference and stock hold are untouched — only the gateway, and what belonged
 * to the old gateway's attempt, change.
 *
 * Before switching, the old gateway is asked once whether the payer in fact
 * paid there. A confirmed payment is settled and the switch refused, so money
 * is never taken twice.
 */
class ChangeOrderPaymentMethod
{
    public function __construct(
        protected PaymentGatewayManager $gateways,
        protected SettlePayment $settle,
        protected RecordPaymentLog $logs,
        protected DistributedLock $lock,
    ) {}

    /**
     * @throws OrderRefused
     * @throws LockTimeout when a settlement is holding the payment
     */
    public function handle(Order $order, string $gateway, ?Request $request = null): void
    {
        $paymentId = $order->payment_id ?? throw new LogicException('An order paid through a gateway has a payment.');

        $paidAt = $this->lock->run(
            key: 'payment:settle:'.$paymentId,
            callback: fn () => $this->change($order->refresh(), $gateway, $request),
            ttlSeconds: 30,
            waitSeconds: 10,
        );

        if ($paidAt === null) {
            return;
        }

        // Settled outside the lock above, which settlement takes itself.
        try {
            $this->settle->handle(Payment::query()->findOrFail($paymentId), $paidAt);
        } catch (GatewayUnavailable) {
            // The sweep will confirm it; either way the method must not change.
        }

        throw OrderRefused::alreadyPaidElsewhere();
    }

    /**
     * @return string|null the old gateway's reference when it reports the payment as paid
     */
    protected function change(Order $order, string $gateway, ?Request $request): ?string
    {
        /** @var Payment $payment */
        $payment = Payment::query()->findOrFail($order->payment_id);

        if ($order->status !== OrderStatus::PaymentPending) {
            throw OrderRefused::notAwaitingPayment();
        }

        if ($payment->status === PaymentStatus::Pending) {
            throw OrderRefused::paymentInFlight();
        }

        if (! in_array($payment->status, [PaymentStatus::Draft, PaymentStatus::Initiated], true)) {
            throw OrderRefused::notAwaitingPayment();
        }

        if ($payment->expires_at === null || ! $payment->expires_at->isFuture()) {
            throw OrderRefused::paymentWindowClosed();
        }

        $previous = (string) $payment->gateway;

        if ($gateway === $previous) {
            return null;
        }

        $toWallet = WalletPaymentMethod::is($gateway);

        if ($toWallet) {
            $account = $order->businessAccount()->firstOrFail();

            if (! WalletPaymentMethod::canCover($account, $payment->amount)) {
                throw OrderRefused::walletInsufficient();
            }
        } elseif (! in_array($gateway, $this->gateways->availableFor($payment->amount->currency), true)) {
            throw OrderRefused::paymentMethodUnavailable();
        }

        $alreadyPaid = $this->paidAtPrevious($payment, $previous);

        if ($alreadyPaid !== null) {
            return $alreadyPaid;
        }

        $payment->forceFill([
            'gateway' => $gateway,
            // Stamped for the gateway the payment will now be taken through; the
            // wallet is no gateway and has no mode.
            'gateway_mode' => $toWallet
                ? null
                : ($this->gateways->driver($gateway)->isSandbox() ? GatewayCredentials::SANDBOX : GatewayCredentials::LIVE),
            // Belonged to the previous gateway's attempt.
            'gateway_reference' => null,
            'gateway_session_reference' => null,
            'payer_address_snapshot' => null,
        ])->save();

        $this->logs->handle(
            gateway: $gateway,
            direction: PaymentLog::OUTBOUND,
            event: 'method_changed',
            payment: $payment,
            amount: $payment->amount,
            outcome: 'changed',
            context: ['from' => $previous, 'to' => $gateway, 'order' => $order->reference],
            request: $request,
        );

        return null;
    }

    /**
     * Ask the old gateway whether the payer already paid there.
     *
     * @return string|null its reference for the payment when it did
     */
    protected function paidAtPrevious(Payment $payment, string $previous): ?string
    {
        if ($previous === '' || ! $this->gateways->isImplemented($previous)) {
            return null;
        }

        $driver = $this->gateways->driver($previous);

        if (! $driver->supports(GatewayCapability::StatusQuery)) {
            return null;
        }

        try {
            $result = $driver->status((string) ($payment->gateway_reference ?: $payment->reference));
        } catch (GatewayUnavailable) {
            // It cannot say — and a gateway that is down is often why the payer is here.
            return null;
        }

        return $result->isPaid() ? $result->gatewayReference : null;
    }
}
