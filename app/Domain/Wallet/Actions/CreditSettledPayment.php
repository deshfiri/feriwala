<?php

namespace App\Domain\Wallet\Actions;

use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Models\Payment;
use App\Domain\Wallet\Data\PostingContext;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Domain\Wallet\WalletService;
use Illuminate\Log\LogManager;

/**
 * Turns a settled payment into wallet money — but only when that is what it was
 * for (§23.1, §26.3).
 *
 * Two purposes put money **into** a wallet: a deposit and a top-up. Everything
 * else a customer pays for is money paid **to** Feriwala — an activation fee, a
 * package, a renewal — and crediting a wallet for those would hand back the
 * money that was just charged. So this action answers "is this wallet money?"
 * rather than "did a payment settle?", and the answer is no for ten of the
 * twelve purposes.
 *
 * Crediting happens **exactly once**, whatever the gateway does. The posting
 * carries an idempotency key derived from the payment's own reference, so a
 * retried IPN, a manual retry and a reconciliation sweep all land on the same
 * key and the second one gets the first one's transaction back instead of
 * doubling the balance. That is the durable guard; the settlement lock and the
 * status check in front of it are the cheap ones.
 *
 * Allocation between deposit and usable balance belongs to P2-19 (§24.4). What
 * is settled here is where the money lands — the total — and which entry type
 * explains it, so a statement says "Deposit" or "Top-up" rather than leaving
 * both as an undifferentiated credit.
 */
class CreditSettledPayment
{
    public function __construct(
        protected WalletService $wallet,
        protected LogManager $log,
    ) {}

    /**
     * Whether this payment is wallet money at all.
     *
     * Public so a screen and a retry can ask without attempting the credit.
     */
    public function applies(Payment $payment): bool
    {
        return $this->typeFor($payment->purpose) !== null;
    }

    /**
     * Credit the account's wallet for this payment, or do nothing at all.
     *
     * Returns null when the payment was not wallet money, has not settled, or
     * the account has no wallet to credit — never a partial posting.
     */
    public function handle(Payment $payment): ?WalletTransaction
    {
        $type = $this->typeFor($payment->purpose);

        if ($type === null) {
            return null;
        }

        // Money that has not actually arrived is not a balance. Every caller
        // settles first, but this is the invariant, not their calling order.
        if (! $payment->status->isSettled()) {
            return null;
        }

        $wallet = Wallet::query()
            ->where('business_account_id', $payment->business_account_id)
            ->first();

        if ($wallet === null) {
            /*
             * A wallet opens with the activation and never on demand. Opening
             * one here would mean a top-up quietly creating the wallet it pays
             * into for an account that was never activated — so the money is
             * left visibly unapplied for an administrator instead.
             */
            $this->log->channel('wallet')->critical('Settled wallet payment has no wallet to credit', [
                'payment' => $payment->reference,
                'business_account' => $payment->business_account_id,
                'purpose' => $payment->purpose->value,
                'amount' => $payment->amount->toDecimal(),
            ]);

            $this->flagUnapplied($payment, 'This account has no wallet to credit.');

            return null;
        }

        $transaction = $this->wallet->credit($wallet, $type, $payment->amount, new PostingContext(
            source: 'payment',
            description: $payment->purpose->label().' — '.$payment->reference,

            // Derived from the payment, so every route into settlement produces
            // the same key and only the first one moves the balance.
            idempotencyKey: 'payment:'.$payment->reference,

            // No `userId`: a payment belongs to the business account, not to
            // whichever person happened to click pay (D1, D23).
            paymentId: $payment->id,
        ));

        $this->markApplied($payment);

        return $transaction;
    }

    /**
     * Record that confirmed money has not reached a wallet.
     *
     * Not a payment status. `Paid` is true — the provider confirmed it — and
     * `ReconciliationRequired` already means something else: money confirmed
     * after its checkout had closed. What needs reconciling here is the credit,
     * so that is what is written down, beside the payment rather than instead of
     * what the payment says about itself.
     */
    public function flagUnapplied(Payment $payment, string $reason): void
    {
        $payment->forceFill([
            'wallet_credit_failed_at' => now(),
            'wallet_credit_failure_reason' => $reason,
        ])->save();
    }

    /**
     * Clear the flag once the money is where it was paid to go.
     */
    protected function markApplied(Payment $payment): void
    {
        $payment->forceFill([
            'wallet_credited_at' => $payment->wallet_credited_at ?? now(),
            'wallet_credit_failed_at' => null,
            'wallet_credit_failure_reason' => null,
        ])->save();
    }

    /**
     * The entry type this purpose credits as, or null if it is not wallet money.
     *
     * Written as a total match rather than an `in_array` of the two that are, so
     * a purpose added later cannot fall through into "credit the wallet" by
     * default — the compiler asks what it should do instead.
     */
    protected function typeFor(PaymentPurpose $purpose): ?LedgerTransactionType
    {
        return match ($purpose) {
            PaymentPurpose::WalletDeposit => LedgerTransactionType::DepositCredit,
            PaymentPurpose::WalletTopUp => LedgerTransactionType::TopUpCredit,

            // Money paid to Feriwala, not into the wallet. Crediting any of
            // these would give back what was just charged.
            PaymentPurpose::Activation,
            PaymentPurpose::PackageRenewal,
            PaymentPurpose::PackageUpgrade,
            PaymentPurpose::PackageDowngrade,
            PaymentPurpose::WholesaleOrder,
            PaymentPurpose::WebsiteOrder,
            PaymentPurpose::WebsiteSetup,
            PaymentPurpose::DomainCharge,
            PaymentPurpose::HostingCharge,
            PaymentPurpose::MaintenanceCharge => null,
        };
    }
}
