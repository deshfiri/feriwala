<?php

namespace App\Domain\Order\Actions;

use App\Domain\Billing\Actions\SettlePayment;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Exceptions\OrderRefused;
use App\Domain\Order\Models\Order;
use App\Domain\Wallet\Data\PostingContext;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Exceptions\WalletOperationRefused;
use App\Domain\Wallet\WalletPaymentMethod;
use App\Domain\Wallet\WalletService;
use App\Models\User;
use App\Support\Concurrency\DistributedLock;
use App\Support\Concurrency\Exceptions\LockTimeout;
use Illuminate\Database\DatabaseManager;
use LogicException;

/**
 * Pay an order's payment out of the account's own wallet (§23, §26).
 *
 * The debit and the payment's move to paid are one database transaction under the
 * same settlement lock a gateway confirmation takes, so the wallet can never be
 * charged for a payment that stays open, nor a payment marked paid that charged
 * nothing. Platform credit is spent first, then withdrawable money.
 *
 * Idempotent twice over: a payment already paid returns without touching the
 * wallet, and the debit's own key is derived from the payment, so a repeated
 * request that raced past the first check still posts once.
 *
 * What a paid order unlocks — stock committed, the invoice, the supplier payable
 * — is the same consequence a gateway payment has, run after the money moved.
 */
class PayOrderFromWallet
{
    public function __construct(
        protected WalletService $wallets,
        protected SettlePayment $settlement,
        protected DistributedLock $lock,
        protected DatabaseManager $database,
    ) {}

    /**
     * @return bool whether this call moved the money (false when it was already paid)
     *
     * @throws OrderRefused
     * @throws LockTimeout when a settlement is holding the payment
     */
    public function handle(Order $order, User $payer): bool
    {
        $paymentId = $order->payment_id ?? throw new LogicException('An order paid from the wallet has a payment.');

        $payment = $this->lock->run(
            key: 'payment:settle:'.$paymentId,
            callback: fn () => $this->pay($order->refresh(), $payer),
            ttlSeconds: 30,
            waitSeconds: 10,
        );

        if ($payment === null) {
            return false;
        }

        // Outside the transaction and never allowed to undo the payment: the
        // money moved either way, and an order left waiting is picked up by the
        // sweep that runs the same idempotent confirmation.
        $this->settlement->applyPurpose($payment);

        return true;
    }

    /**
     * @return Payment|null the payment this call settled, or null when it was already settled
     */
    protected function pay(Order $order, User $payer): ?Payment
    {
        return $this->database->transaction(function () use ($order, $payer) {
            /** @var Payment $payment */
            $payment = Payment::query()->lockForUpdate()->findOrFail($order->payment_id);

            if ($payment->status->isSettled()) {
                return null;
            }

            if ($order->status !== OrderStatus::PaymentPending) {
                throw OrderRefused::notAwaitingPayment();
            }

            // Money is owed only when the payment is for something; a payment of
            // nothing — a Non-Conditional order with no delivery charge — has no
            // method to choose and nothing to take.
            $owed = $payment->amount->isPositive();

            // An order paid in full (D34) always owes its whole total; a payment of
            // nothing on one is a fault, never a reason to mark it paid.
            if (! $owed && $order->usesFullPaymentModel()) {
                throw OrderRefused::nothingToPay();
            }

            if ($owed && ! WalletPaymentMethod::is($payment->gateway)) {
                throw OrderRefused::paymentMethodUnavailable();
            }

            if (! in_array($payment->status, [PaymentStatus::Draft, PaymentStatus::Initiated], true)) {
                throw OrderRefused::notAwaitingPayment();
            }

            if ($payment->expires_at === null || ! $payment->expires_at->isFuture()) {
                throw OrderRefused::paymentWindowClosed();
            }

            $debit = null;

            if ($owed) {
                $account = $order->businessAccount()->firstOrFail();
                $wallet = WalletPaymentMethod::walletFor($account) ?? throw OrderRefused::walletUnavailable();

                try {
                    $debit = $this->wallets->debit(
                        $wallet,
                        LedgerTransactionType::WholesaleOrderDebit,
                        $payment->amount,
                        new PostingContext(
                            source: 'order',
                            description: __('wallet.pay.description', ['order' => $order->reference]),
                            idempotencyKey: 'order-wallet-payment:'.$payment->reference,
                            paymentId: $payment->id,
                            userId: $payer->id,
                        ),
                    );
                } catch (WalletOperationRefused $refused) {
                    // The wallet says what stopped it only in words; the balance, read
                    // again, says whether it was the amount.
                    throw WalletPaymentMethod::canCover($account, $payment->amount)
                        ? OrderRefused::walletUnavailable()
                        : OrderRefused::walletInsufficient();
                }
            }

            if ($payment->status === PaymentStatus::Draft) {
                $payment->transitionTo(PaymentStatus::Initiated);
            }

            $payment->transitionTo(PaymentStatus::Paid);

            $payment->forceFill([
                'gateway_reference' => $debit?->reference,
                'completed_at' => now(),
                'settled_amount' => $payment->amount,
            ])->save();

            return $payment;
        });
    }
}
