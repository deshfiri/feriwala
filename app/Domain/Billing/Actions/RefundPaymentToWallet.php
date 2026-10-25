<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\RefundRequest;
use App\Domain\Order\Models\Order;
use App\Domain\Wallet\Data\PostingContext;
use App\Domain\Wallet\Enums\BalanceType;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Domain\Wallet\WalletService;
use App\Integrations\Payment\Data\RefundResult;
use App\Support\Money\Money;
use LogicException;

/**
 * Give money back to the wallet an order was paid from (§26.3).
 *
 * The wallet takes the place of a gateway for this one direction: the refund is
 * a new credit on the ledger, never an edit to the debit it answers.
 *
 * What comes back is the kind that was spent. Platform credit is returned up to
 * what the payment took from platform credit, across every refund against it, and
 * the rest returns as withdrawable money — so a refund can never turn money the
 * account could withdraw into money it cannot, nor the other way round.
 *
 * Idempotent through each credit's key, derived from the refund request, so a
 * retried refund posts once.
 */
class RefundPaymentToWallet
{
    public function __construct(protected WalletService $wallets) {}

    public function handle(Payment $payment, RefundRequest $request): RefundResult
    {
        $wallet = Wallet::query()->where('business_account_id', $payment->business_account_id)->first()
            ?? throw new LogicException('A payment taken from a wallet has a wallet to go back to.');

        $debit = WalletTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->where('idempotency_key', 'order-wallet-payment:'.$payment->reference)
            ->first()
            ?? throw new LogicException('A payment taken from a wallet has the debit that took it.');

        $zero = Money::zero($request->amount->currency);

        $alreadyReturned = WalletTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->where('payment_id', $payment->id)
            ->where('type', LedgerTransactionType::OrderRefundCredit)
            ->get()
            ->reduce(fn (Money $sum, WalletTransaction $credit) => $sum->plus($credit->platform_credit_amount ?? $zero), $zero);

        $platformLeft = $debit->platform_credit_amount->minus($alreadyReturned);
        $platformLeft = $platformLeft->isNegative() ? $zero : $platformLeft;

        $platformPart = $request->amount->greaterThan($platformLeft) ? $platformLeft : $request->amount;
        $withdrawablePart = $request->amount->minus($platformPart);

        $order = Order::query()->where('payment_id', $payment->id)->first();
        $description = __('wallet.pay.refund_description', [
            'order' => $order instanceof Order ? $order->reference : $payment->reference,
        ]);

        $first = null;

        foreach ([
            [BalanceType::PlatformCredit, $platformPart],
            [BalanceType::Withdrawable, $withdrawablePart],
        ] as [$kind, $part]) {
            if (! $part->isPositive()) {
                continue;
            }

            $credit = $this->wallets->credit(
                $wallet,
                LedgerTransactionType::OrderRefundCredit,
                $part,
                new PostingContext(
                    source: 'refund',
                    description: $description,
                    idempotencyKey: 'order-refund:'.$request->public_id.':'.$kind->value,
                    paymentId: $payment->id,
                ),
                $kind,
            );

            $first ??= $credit;
        }

        return RefundResult::succeeded(
            gatewayRefundReference: (string) $first?->reference,
            amount: $request->amount,
            raw: ['wallet' => $wallet->public_id ?? null],
        );
    }
}
