<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Exceptions\RefundRefused;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\RefundRequest;
use App\Domain\Wallet\Actions\CreditSettledPayment;
use App\Domain\Wallet\Data\PostingContext;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\WalletService;
use App\Support\Money\Money;
use Illuminate\Log\LogManager;
use Throwable;

/**
 * The wallet side of a refund (§23.1, §26.3).
 *
 * Most payments are money paid **to** Feriwala — an activation fee, a package,
 * a renewal — and refunding one reverses nothing in a wallet, because nothing
 * was ever credited to one. Only a deposit or a top-up put money in, and only
 * those take it back out.
 *
 * **Never silently overdraws.** The obvious failure this guards against: a
 * top-up is credited, the account spends it, and somebody refunds the top-up.
 * Taking it back would leave a negative available balance nobody authorised. So
 * the balance is checked **before the provider is asked**, in
 * {@see assertPossible()}, rather than discovered after the money has already
 * gone back.
 *
 * The posting itself is idempotent on the refund's own reference, so applying it
 * twice posts once.
 */
class ApplyRefundReversal
{
    public function __construct(
        protected WalletService $wallet,
        protected CreditSettledPayment $credits,
        protected LogManager $log,
    ) {}

    /**
     * Whether refunding this payment touches a wallet at all.
     */
    public function applies(Payment $payment): bool
    {
        return $this->credits->applies($payment);
    }

    /**
     * Refuse now if the wallet could not carry the reversal.
     *
     * Called before the provider is contacted. Not a guarantee — the account can
     * spend the balance between this check and the confirmation — but it turns
     * the common case from "the money is gone and the wallet is negative" into a
     * refusal an administrator can read and act on.
     *
     * @throws RefundRefused
     */
    public function assertPossible(Payment $payment, Money $amount): void
    {
        if (! $this->applies($payment)) {
            return;
        }

        $wallet = $this->walletFor($payment);

        if ($wallet === null) {
            /*
             * Nothing was ever credited, so there is nothing to take back. The
             * payment is refundable through the provider on its own.
             */
            return;
        }

        $available = $wallet->usableBalance();

        if ($amount->greaterThan($available)) {
            throw RefundRefused::walletCannotCover($amount, $available);
        }
    }

    /**
     * Take the money back out, once the provider has confirmed it went back.
     *
     * Never allowed to fail the refund itself: the money has already left the
     * provider, and rolling the refund record back because a posting failed
     * would lose the fact that it happened. The failure is loud instead, and the
     * refund is left visibly without its reversal.
     */
    public function handle(Payment $payment, RefundRequest $refund): void
    {
        if (! $this->applies($payment)) {
            return;
        }

        if ($refund->wallet_transaction_id !== null) {
            return;
        }

        $wallet = $this->walletFor($payment);

        if ($wallet === null) {
            return;
        }

        try {
            $transaction = $this->wallet->debit(
                $wallet,
                LedgerTransactionType::RefundDebit,
                $refund->amount_minor,
                new PostingContext(
                    source: 'refund',
                    description: 'Refund — '.$payment->reference,

                    // The refund request's own identity, so applying this twice
                    // posts once.
                    idempotencyKey: 'refund:'.$refund->public_id,
                    paymentId: $payment->id,
                ),
            );
        } catch (Throwable $throwable) {
            /*
             * The wallet was spent between the check and the confirmation, or
             * something else went wrong. The refund stands — the provider sent
             * the money — and this is written loudly rather than swallowed,
             * because a completed refund with no reversal is a real discrepancy
             * somebody has to settle.
             */
            $this->log->channel('wallet')->critical('Could not reverse a refunded wallet payment', [
                'refund' => $refund->public_id,
                'payment' => $payment->reference,
                'business_account' => $payment->business_account_id,
                'amount_minor' => $refund->amount_minor->minorUnits,
                'error' => $throwable->getMessage(),
            ]);

            $refund->forceFill([
                'failure_reason' => 'The refund was sent, but the wallet could not be debited: '
                    .$throwable->getMessage(),
            ])->save();

            return;
        }

        $refund->forceFill(['wallet_transaction_id' => $transaction->id])->save();
    }

    protected function walletFor(Payment $payment): ?Wallet
    {
        return Wallet::query()
            ->where('business_account_id', $payment->business_account_id)
            ->first();
    }
}
