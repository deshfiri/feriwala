<?php

namespace App\Domain\Wallet;

use App\Domain\Wallet\Data\PostingContext;
use App\Domain\Wallet\Enums\LedgerDirection;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Enums\WalletTransactionStatus;
use App\Domain\Wallet\Exceptions\WalletOperationRefused;
use App\Domain\Wallet\Models\LedgerEntry;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Support\Money\Money;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The only thing that moves money in a wallet (§23, §36.1).
 *
 * Every balance change goes through here, and every one of them writes its
 * immutable ledger entry in the **same transaction** as the balance it
 * explains. Nothing else may write a wallet column: a balance that changed
 * without an entry is a figure nobody can account for, and the point of a
 * ledger is that there is no such figure.
 *
 * Five operations, and the difference between them matters:
 *
 *   - **credit** and **debit** move `total`. Value arrives or leaves.
 *   - **hold** and **reserve** move nothing. They move money *within* the
 *     wallet, out of what can be spent and into a bucket that cannot, and the
 *     total is untouched — so they post no ledger entry, because no value
 *     moved. What they write is a transaction that stands for the claim.
 *   - **release** gives a claim back; **capture** turns it into a real debit.
 *
 * A held or reserved claim is a {@see WalletTransaction} whose status is the
 * claim's life. Releasing and capturing go through the state machine, which is
 * what makes each of them happen **exactly once**: the second attempt finds a
 * status that has no move left and is refused.
 *
 * Every write runs under `lockForUpdate` inside a transaction. Two debits
 * arriving together must not both read the same balance and both succeed, and a
 * row lock is the only thing that actually stops them.
 */
class WalletService
{
    public function __construct(
        protected DatabaseManager $database,
    ) {}

    /**
     * Money arrives.
     */
    public function credit(
        Wallet $wallet,
        LedgerTransactionType $type,
        Money $amount,
        PostingContext $context,
    ): WalletTransaction {
        return $this->post($wallet, $type, $amount, $context, LedgerDirection::Credit);
    }

    /**
     * Money leaves.
     *
     * Refused when the wallet has less than the amount available to spend. A
     * negative available balance is not authorised anywhere in P2.A, and an
     * overdraft nobody agreed to is a loan nobody agreed to.
     */
    public function debit(
        Wallet $wallet,
        LedgerTransactionType $type,
        Money $amount,
        PostingContext $context,
    ): WalletTransaction {
        return $this->post($wallet, $type, $amount, $context, LedgerDirection::Debit);
    }

    /**
     * Freeze money where it is (§23.3's On Hold, §24.2's hold balance).
     *
     * Nothing moves in or out — the total is unchanged and no ledger entry is
     * written. What changes is what can be spent.
     */
    public function hold(
        Wallet $wallet,
        LedgerTransactionType $type,
        Money $amount,
        PostingContext $context,
    ): WalletTransaction {
        return $this->claim($wallet, $type, $amount, $context, 'hold_minor', WalletTransactionStatus::OnHold);
    }

    /**
     * Set money aside against a charge that is coming (§24.2's reserved).
     *
     * Reserved money is not spendable, which is the whole point: it is already
     * spoken for.
     */
    public function reserve(
        Wallet $wallet,
        LedgerTransactionType $type,
        Money $amount,
        PostingContext $context,
    ): WalletTransaction {
        return $this->claim($wallet, $type, $amount, $context, 'reserved_minor', WalletTransactionStatus::Pending);
    }

    /**
     * Give a claim back. The money becomes spendable again.
     *
     * Exactly once: the state machine has no second move out of a released
     * transaction, so a repeat is refused rather than releasing twice.
     */
    public function release(WalletTransaction $claim): WalletTransaction
    {
        return $this->settleClaim($claim, WalletTransactionStatus::Cancelled, capture: false);
    }

    /**
     * Turn a claim into a real debit.
     *
     * The bucket empties and the total falls, in one transaction, with the
     * ledger entry that explains it.
     */
    public function capture(WalletTransaction $claim): WalletTransaction
    {
        return $this->settleClaim($claim, WalletTransactionStatus::Settled, capture: true);
    }

    /**
     * The one write path.
     */
    protected function post(
        Wallet $wallet,
        LedgerTransactionType $type,
        Money $amount,
        PostingContext $context,
        ?LedgerDirection $direction = null,
    ): WalletTransaction {
        $direction = $this->directionFor($type, $context, $direction);

        $this->assertPostable($wallet, $type, $amount, $context);

        // Fast path, and the reason a retry costs nothing: the command was
        // already carried out, so hand back what it produced.
        if ($existing = $this->findByKey($context->idempotencyKey)) {
            return $existing;
        }

        try {
            return $this->database->transaction(function () use (
                $wallet, $type, $amount, $context, $direction
            ) {
                /** @var Wallet $locked */
                $locked = Wallet::query()->lockForUpdate()->findOrFail($wallet->id);

                $isCredit = $direction->isCredit();

                /*
                 * Checked **inside** the lock, not before it. Between a caller
                 * reading a balance and this moment another debit can have
                 * taken the money, and only the re-check under the lock knows.
                 */
                if (! $isCredit && $locked->usableBalance()->lessThan($amount)) {
                    throw WalletOperationRefused::insufficientBalance($amount, $locked->usableBalance());
                }

                $before = $locked->total_minor;
                $after = $isCredit ? $before->plus($amount) : $before->minus($amount);

                // A posting that reaches here has moved value, so it is settled
                // by definition — the statuses before it belong to a claim.
                $transaction = $this->openTransaction(
                    $locked, $type, $amount, $context, $direction, WalletTransactionStatus::Settled,
                );

                $locked->forceFill(['total_minor' => $after])->save();

                $this->writeEntry($locked, $transaction, $context, [
                    'credit_minor' => $isCredit ? $amount : Money::zero($amount->currency),
                    'debit_minor' => $isCredit ? Money::zero($amount->currency) : $amount,
                    'balance_before_minor' => $before,
                    'balance_after_minor' => $after,
                ]);

                $wallet->setRawAttributes($locked->getAttributes(), sync: true);

                return $transaction;
            });
        } catch (UniqueConstraintViolationException $exception) {
            /*
             * Two identical commands raced and the index settled it. The whole
             * transaction rolled back, so nothing is half-written; the winner's
             * transaction is the answer to both.
             */
            $existing = $this->findByKey($context->idempotencyKey);

            if ($existing === null) {
                throw $exception;
            }

            return $existing;
        }
    }

    /**
     * A hold or a reservation: money moved between buckets, none in or out.
     */
    protected function claim(
        Wallet $wallet,
        LedgerTransactionType $type,
        Money $amount,
        PostingContext $context,
        string $bucket,
        WalletTransactionStatus $status,
    ): WalletTransaction {
        $direction = $this->directionFor($type, $context, LedgerDirection::Debit);

        $this->assertPostable($wallet, $type, $amount, $context);

        if ($existing = $this->findByKey($context->idempotencyKey)) {
            return $existing;
        }

        try {
            return $this->database->transaction(function () use (
                $wallet, $type, $amount, $context, $bucket, $status, $direction
            ) {
                /** @var Wallet $locked */
                $locked = Wallet::query()->lockForUpdate()->findOrFail($wallet->id);

                // A claim on money that is not there would make the wallet
                // report a spendable balance it does not have.
                if ($locked->usableBalance()->lessThan($amount)) {
                    throw WalletOperationRefused::insufficientBalance($amount, $locked->usableBalance());
                }

                $transaction = $this->openTransaction($locked, $type, $amount, $context, $direction, $status);

                $locked->forceFill([
                    $bucket => $locked->{$bucket}->plus($amount),
                ])->save();

                $wallet->setRawAttributes($locked->getAttributes(), sync: true);

                return $transaction;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $existing = $this->findByKey($context->idempotencyKey);

            if ($existing === null) {
                throw $exception;
            }

            return $existing;
        }
    }

    /**
     * End a claim, once.
     */
    protected function settleClaim(
        WalletTransaction $claim,
        WalletTransactionStatus $to,
        bool $capture,
    ): WalletTransaction {
        return $this->database->transaction(function () use ($claim, $to, $capture) {
            /** @var WalletTransaction|null $locked */
            $locked = WalletTransaction::query()->lockForUpdate()->find($claim->id);

            if ($locked === null || ! $locked->canTransitionTo($to)) {
                throw WalletOperationRefused::alreadySettled($claim->reference);
            }

            /** @var Wallet $wallet */
            $wallet = Wallet::query()->lockForUpdate()->findOrFail($locked->wallet_id);

            $bucket = $locked->status === WalletTransactionStatus::OnHold
                ? 'hold_minor'
                : 'reserved_minor';

            $amount = $locked->amount_minor;

            // Read before anything is written: after the update the wallet no
            // longer knows where it started.
            $before = $wallet->total_minor;

            $changes = [$bucket => $wallet->{$bucket}->minus($amount)];

            if ($capture) {
                $changes['total_minor'] = $before->minus($amount);
            }

            $wallet->forceFill($changes)->save();

            $locked->transitionTo($to);
            $locked->save();

            if ($capture) {
                // The claim becomes a real movement, and only now does an entry
                // exist — because only now has value actually left.
                $this->writeEntry($wallet, $locked, new PostingContext(
                    source: $locked->source,
                    description: $locked->description,
                    idempotencyKey: $locked->idempotency_key === null
                        ? null
                        : $locked->idempotency_key.':capture',
                    actorId: $locked->created_by,
                    paymentId: $locked->payment_id,
                    userId: $locked->user_id,
                ), [
                    'credit_minor' => Money::zero($amount->currency),
                    'debit_minor' => $amount,
                    'balance_before_minor' => $before,
                    'balance_after_minor' => $wallet->total_minor,
                ]);
            }

            $claim->setRawAttributes($locked->getAttributes(), sync: true);

            return $locked;
        });
    }

    /**
     * Refuse everything that must not be written, before anything is.
     */
    protected function assertPostable(
        Wallet $wallet,
        LedgerTransactionType $type,
        Money $amount,
        PostingContext $context,
    ): void {
        if (! $amount->isPositive()) {
            throw WalletOperationRefused::notPositive();
        }

        // D4: no exchange-rate accounting in v1, so a conversion here would be
        // a rate nobody agreed applied to somebody's money.
        if ($amount->currency->value !== $wallet->currency_code) {
            throw WalletOperationRefused::currencyMismatch($wallet->currency_code, $amount->currency->value);
        }

        if ($type->requiresReason() && blank($context->reason)) {
            throw WalletOperationRefused::reasonRequired($type);
        }

        if ($type->requiresActor() && $context->actorId === null) {
            throw WalletOperationRefused::actorRequired($type);
        }
    }

    protected function directionFor(
        LedgerTransactionType $type,
        PostingContext $context,
        ?LedgerDirection $fallback,
    ): LedgerDirection {
        $direction = $type->direction() ?? $context->direction ?? $fallback;

        if ($direction === null) {
            throw WalletOperationRefused::directionRequired($type);
        }

        return $direction;
    }

    protected function findByKey(?string $key): ?WalletTransaction
    {
        return $key === null
            ? null
            : WalletTransaction::query()->where('idempotency_key', $key)->first();
    }

    protected function openTransaction(
        Wallet $wallet,
        LedgerTransactionType $type,
        Money $amount,
        PostingContext $context,
        LedgerDirection $direction,
        WalletTransactionStatus $status,
    ): WalletTransaction {
        return WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'business_account_id' => $wallet->business_account_id,
            'user_id' => $context->userId,
            'type' => $type,
            'direction' => $direction,
            'source' => $context->source,
            'amount_minor' => $amount,
            'currency_code' => $amount->currency->value,
            'status' => $status,
            'payment_id' => $context->paymentId,
            'description' => $context->description,
            'internal_note' => $context->internalNote,
            'reason' => $context->reason,
            'created_by' => $context->actorId,
            'approved_by' => $context->approvedBy,
            'idempotency_key' => $context->idempotencyKey,
        ]);
    }

    /**
     * @param  array<string, Money>  $amounts
     */
    protected function writeEntry(
        Wallet $wallet,
        WalletTransaction $transaction,
        PostingContext $context,
        array $amounts,
    ): LedgerEntry {
        return LedgerEntry::create([
            'wallet_id' => $wallet->id,
            'business_account_id' => $wallet->business_account_id,
            'user_id' => $context->userId,
            'wallet_transaction_id' => $transaction->id,
            'type' => $transaction->type,
            'source' => $context->source,
            'payment_id' => $context->paymentId,
            'user_package_id' => $context->userPackageId,
            'currency_code' => $wallet->currency_code,

            ...$amounts,

            // What every bucket held immediately after this entry (§23.2), so a
            // statement from last March reads without reconstructing March.
            'pending_minor' => $wallet->pending_minor,
            'reserved_minor' => $wallet->reserved_minor,
            'hold_minor' => $wallet->hold_minor,
            'available_minor' => $wallet->usableBalance(),

            'status' => $transaction->status,
            'created_by' => $context->actorId,
            'approved_by' => $context->approvedBy,
            'description' => $context->description,
            'internal_note' => $context->internalNote,
            'idempotency_key' => $context->idempotencyKey,
            'corrects_ledger_entry_id' => $context->correctsLedgerEntryId,
            'created_at' => now(),
        ]);
    }
}
