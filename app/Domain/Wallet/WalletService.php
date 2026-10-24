<?php

namespace App\Domain\Wallet;

use App\Domain\Wallet\Data\PostingContext;
use App\Domain\Wallet\Enums\BalanceType;
use App\Domain\Wallet\Enums\LedgerDirection;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Enums\WalletTransactionStatus;
use App\Domain\Wallet\Exceptions\WalletOperationRefused;
use App\Domain\Wallet\Models\LedgerEntry;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Domain\Wallet\Models\WalletTransactionEvent;
use App\Support\Money\Money;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

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
 *     moved. §23.1 names no reserve and no hold; §23.3 makes them **statuses**
 *     a transaction sits in, and that is what they write.
 *   - **release** gives a claim back; **capture** turns it into a real debit.
 *
 * Every one of those changes appends a {@see WalletTransactionEvent}, in the
 * same database transaction. That is where the audit trail for bucket movement
 * lives: `wallet_transactions.status` says where a claim is now and would
 * otherwise overwrite the evidence that it was ever reserved at all.
 *
 * A held or reserved claim is a {@see WalletTransaction} whose status is the
 * claim's life. Releasing and capturing go through the state machine, which is
 * what makes each of them happen **exactly once**: the second attempt finds a
 * status that has no move left and is refused.
 *
 * **Two kinds of money.** The wallet holds platform credit (spendable inside
 * Banij, never withdrawable) and withdrawable money, and every ledger entry
 * says which it moved ({@see BalanceType}). A credit lands in the kind its type
 * maps to, or the kind a person chose where the type leaves it open. A debit
 * spends platform credit first and withdrawable money only for the rest; when
 * both are used the posting writes **one entry per kind**, so the split is on
 * the ledger and a refund or reversal can return money to the kinds it left.
 * A withdrawal's reservation is withdrawable money and nothing else.
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
     *
     * `$balanceType` is the kind of money it is. Left null, it is the kind the
     * type maps to — a top-up is platform credit, a sale's earning is
     * withdrawable — and a type that maps to nothing is refused rather than
     * guessed at.
     */
    public function credit(
        Wallet $wallet,
        LedgerTransactionType $type,
        Money $amount,
        PostingContext $context,
        ?BalanceType $balanceType = null,
    ): WalletTransaction {
        return $this->post($wallet, $type, $amount, $context, LedgerDirection::Credit, $balanceType);
    }

    /**
     * Money leaves.
     *
     * Refused when the wallet has less than the amount available to spend. A
     * negative available balance is not authorised anywhere in P2.A, and an
     * overdraft nobody agreed to is a loan nobody agreed to.
     *
     * Platform credit is spent first, then withdrawable money. `$only` names the
     * one kind the whole amount must come from: a reversal takes back the kind
     * the original brought in, and is refused when that kind is no longer there,
     * never paid for out of the other kind.
     */
    public function debit(
        Wallet $wallet,
        LedgerTransactionType $type,
        Money $amount,
        PostingContext $context,
        ?BalanceType $only = null,
    ): WalletTransaction {
        return $this->post($wallet, $type, $amount, $context, LedgerDirection::Debit, $only);
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
        return $this->claim($wallet, $type, $amount, $context, 'hold', WalletTransactionStatus::OnHold);
    }

    /**
     * Set money aside against a charge that is coming (§24.2's reserved).
     *
     * Reserved money is not spendable, which is the whole point: it is already
     * spoken for. The claim remembers exactly how much of it came from platform
     * credit and how much from withdrawable money, and releasing or capturing it
     * hands back or spends those same amounts. A withdrawal can only ever claim
     * withdrawable money; any other claim takes platform credit first.
     */
    public function reserve(
        Wallet $wallet,
        LedgerTransactionType $type,
        Money $amount,
        PostingContext $context,
    ): WalletTransaction {
        return $this->claim($wallet, $type, $amount, $context, 'reserved', WalletTransactionStatus::Pending);
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
     * Turn platform credit into withdrawable money.
     *
     * The one way platform credit ever becomes withdrawable. The total does not
     * change: a debit entry takes the amount out of platform credit and a credit
     * entry puts it into withdrawable money, in one transaction, and the two
     * share a conversion reference so they read as the one decision they are.
     * Nothing already written is touched.
     *
     * Refused when less than the amount is available as platform credit — the
     * part of it that is not already held or reserved. Idempotent on the
     * context's key, which is required: a conversion moves money out of the
     * platform's reach, so a retry must find the first one rather than make a
     * second.
     *
     * @return array{debit: WalletTransaction, credit: WalletTransaction}
     *
     * @throws WalletOperationRefused
     */
    public function convertToWithdrawable(Wallet $wallet, Money $amount, PostingContext $context): array
    {
        if ($context->idempotencyKey === null) {
            throw new InvalidArgumentException('A balance conversion needs an idempotency key.');
        }

        $this->assertPostable($wallet, LedgerTransactionType::BalanceConversionDebit, $amount, $context);

        $debitKey = $context->idempotencyKey.':debit';
        $creditKey = $context->idempotencyKey.':credit';

        if ($existing = $this->findByKey($debitKey)) {
            return ['debit' => $existing, 'credit' => $this->findByKey($creditKey) ?? $existing];
        }

        try {
            return $this->database->transaction(function () use ($wallet, $amount, $context, $debitKey, $creditKey) {
                /** @var Wallet $locked */
                $locked = Wallet::query()->lockForUpdate()->findOrFail($wallet->id);

                // Only platform credit that is actually free: not frozen, not
                // pending, not set aside against a charge.
                $platform = $locked->platformCredit();
                $available = $locked->platformCreditAvailable();

                if ($amount->greaterThan($available)) {
                    throw WalletOperationRefused::insufficientPlatformCredit($amount, $available);
                }

                $total = $locked->total;
                $reference = (string) Str::ulid();

                $locked->forceFill([
                    'platform_credit' => $platform->minus($amount),
                ])->save();

                $out = $this->openTransaction(
                    $locked, LedgerTransactionType::BalanceConversionDebit, $amount,
                    $this->contextWithKey($context, $debitKey), LedgerDirection::Debit,
                    WalletTransactionStatus::Settled, $amount, $reference,
                );

                $outEntry = $this->writeEntry($locked, $out, $this->contextWithKey($context, $debitKey), [
                    'credit' => Money::zero($amount->currency),
                    'debit' => $amount,
                    'balance_before' => $total,
                    'balance_after' => $total->minus($amount),
                ], BalanceType::PlatformCredit, $debitKey, $reference);

                $in = $this->openTransaction(
                    $locked, LedgerTransactionType::BalanceConversionCredit, $amount,
                    $this->contextWithKey($context, $creditKey), LedgerDirection::Credit,
                    WalletTransactionStatus::Settled, Money::zero($amount->currency), $reference,
                );

                $inEntry = $this->writeEntry($locked, $in, $this->contextWithKey($context, $creditKey), [
                    'credit' => $amount,
                    'debit' => Money::zero($amount->currency),
                    'balance_before' => $total->minus($amount),
                    'balance_after' => $total,
                ], BalanceType::Withdrawable, $creditKey, $reference);

                foreach ([[$out, $outEntry, $total, $total->minus($amount), $debitKey], [$in, $inEntry, $total->minus($amount), $total, $creditKey]] as [$transaction, $entry, $from, $to, $key]) {
                    $this->recordEvent($locked, $transaction, $this->contextWithKey($context, $key), [
                        'from_status' => null,
                        'to_status' => $transaction->status,
                        'total_before' => $from,
                        'total_after' => $to,
                        'ledger_entry_id' => $entry->id,
                    ]);
                }

                $wallet->setRawAttributes($locked->getAttributes(), sync: true);

                return ['debit' => $out, 'credit' => $in];
            });
        } catch (UniqueConstraintViolationException $exception) {
            $existing = $this->findByKey($debitKey);

            if ($existing === null) {
                throw $exception;
            }

            return ['debit' => $existing, 'credit' => $this->findByKey($creditKey) ?? $existing];
        }
    }

    /**
     * The one write path.
     *
     * For a credit, `$balanceType` is the kind of money it is; for a debit it is
     * the kind to spend first.
     */
    protected function post(
        Wallet $wallet,
        LedgerTransactionType $type,
        Money $amount,
        PostingContext $context,
        ?LedgerDirection $direction = null,
        ?BalanceType $balanceType = null,
    ): WalletTransaction {
        $direction = $this->directionFor($type, $context, $direction);

        $this->assertPostable($wallet, $type, $amount, $context);

        if ($direction->isCredit()) {
            $balanceType ??= $type->creditBalanceType()
                ?? throw WalletOperationRefused::balanceTypeRequired($type);
        }

        // Fast path, and the reason a retry costs nothing: the command was
        // already carried out, so hand back what it produced.
        if ($existing = $this->findByKey($context->idempotencyKey)) {
            return $existing;
        }

        try {
            return $this->database->transaction(function () use (
                $wallet, $type, $amount, $context, $direction, $balanceType
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

                $zero = Money::zero($amount->currency);

                if ($isCredit) {
                    [$platform, $withdrawable] = $balanceType?->isPlatformCredit() === true
                        ? [$amount, $zero]
                        : [$zero, $amount];
                } else {
                    try {
                        [$platform, $withdrawable] = $locked->allocateSpend($amount, $balanceType);
                    } catch (LogicException) {
                        throw $balanceType === null
                            ? WalletOperationRefused::insufficientBalance($amount, $locked->usableBalance())
                            : WalletOperationRefused::insufficientOfKind(
                                $balanceType,
                                $amount,
                                $balanceType->isPlatformCredit() ? $locked->platformCreditFree() : $locked->withdrawableFree(),
                            );
                    }
                }

                $before = $locked->total;
                $after = $isCredit ? $before->plus($amount) : $before->minus($amount);

                // A posting that reaches here has moved value, so it is settled
                // by definition — the statuses before it belong to a claim.
                $transaction = $this->openTransaction(
                    $locked, $type, $amount, $context, $direction, WalletTransactionStatus::Settled, $platform,
                );

                $locked->forceFill([
                    'total' => $after,
                    'platform_credit' => $isCredit
                        ? $locked->platformCredit()->plus($platform)
                        : $locked->platformCredit()->minus($platform),
                ])->save();

                $entries = $this->writeEntriesFor($locked, $transaction, $context, $isCredit, $before, $platform, $withdrawable);

                $this->recordEvent($locked, $transaction, $context, [
                    'from_status' => null,
                    'to_status' => $transaction->status,
                    'total_before' => $before,
                    'total_after' => $after,
                    'ledger_entry_id' => $entries[0]->id,
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
     * Write one entry per kind of money a posting moved, in order, each one
     * picking up where the last left off so the chain of balances stays whole.
     *
     * @return non-empty-list<LedgerEntry>
     */
    protected function writeEntriesFor(
        Wallet $wallet,
        WalletTransaction $transaction,
        PostingContext $context,
        bool $isCredit,
        Money $before,
        Money $platform,
        Money $withdrawable,
    ): array {
        $parts = [];

        if (! $platform->isZero()) {
            $parts[] = [BalanceType::PlatformCredit, $platform];
        }

        if (! $withdrawable->isZero()) {
            $parts[] = [BalanceType::Withdrawable, $withdrawable];
        }

        // A single entry keeps the command's own key; a split needs one key per
        // entry, because the ledger's key is unique and each entry is its own row.
        $split = count($parts) > 1;
        $running = $before;
        $entries = [];

        foreach ($parts as [$kind, $part]) {
            $next = $isCredit ? $running->plus($part) : $running->minus($part);
            $zero = Money::zero($part->currency);

            $entries[] = $this->writeEntry($wallet, $transaction, $context, [
                'credit' => $isCredit ? $part : $zero,
                'debit' => $isCredit ? $zero : $part,
                'balance_before' => $running,
                'balance_after' => $next,
            ], $kind, $split && $context->idempotencyKey !== null ? $context->idempotencyKey.':'.$kind->value : $context->idempotencyKey);

            $running = $next;
        }

        /** @var non-empty-list<LedgerEntry> $entries */
        return $entries;
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

                // Which kind of money the claim takes, and how much of each. A
                // withdrawal can only ever claim withdrawable money; any other
                // claim — a purchase, a fee — takes platform credit first. The
                // split is remembered on the claim, so releasing it, capturing
                // it or refunding it returns exactly these amounts.
                $only = $type === LedgerTransactionType::WithdrawalDebit ? BalanceType::Withdrawable : null;

                try {
                    [$platform] = $locked->allocateSpend($amount, $only);
                } catch (LogicException) {
                    throw $only === null
                        ? WalletOperationRefused::insufficientBalance($amount, $locked->usableBalance())
                        : WalletOperationRefused::insufficientOfKind($only, $amount, $locked->withdrawableFree());
                }

                $transaction = $this->openTransaction($locked, $type, $amount, $context, $direction, $status, $platform);

                $bucketBefore = $locked->{$bucket};

                $locked->forceFill([
                    $bucket => $bucketBefore->plus($amount),
                    $bucket.'_platform_credit' => $locked->{$bucket.'_platform_credit'}->plus($platform),
                ])->save();

                /*
                 * No ledger entry — no value moved (§23.1 names no reserve and
                 * no hold; §23.3 makes them statuses). The claim is recorded
                 * here instead, with the bucket it moved and what that bucket
                 * held on either side of it.
                 */
                $this->recordEvent($locked, $transaction, $context, [
                    'from_status' => null,
                    'to_status' => $status,
                    'bucket' => $bucket,
                    'bucket_before' => $bucketBefore,
                    'bucket_after' => $locked->{$bucket},
                    'total_before' => $locked->total,
                    'total_after' => $locked->total,
                ]);

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
                ? 'hold'
                : 'reserved';

            $amount = $locked->amount;

            // Read before anything is written: after the update the wallet no
            // longer knows where it started.
            $before = $wallet->total;
            $bucketBefore = $wallet->{$bucket};
            $zero = Money::zero($amount->currency);

            // Exactly the split the claim took when it was made — never worked
            // out again, so releasing or capturing it cannot turn one kind of
            // money into the other. A claim made before typed balances took
            // none from platform credit, which is what its default says.
            $platform = $locked->platform_credit_amount;
            $withdrawable = $amount->minus($platform);

            $changes = [
                $bucket => $bucketBefore->minus($amount),
                $bucket.'_platform_credit' => $wallet->{$bucket.'_platform_credit'}->minus($platform),
            ];

            if ($capture) {
                $changes['total'] = $before->minus($amount);
                $changes['platform_credit'] = $wallet->platformCredit()->minus($platform);
            }

            $wallet->forceFill($changes)->save();

            $from = $locked->status;

            $locked->transitionTo($to);
            $locked->save();

            $entry = null;

            if ($capture) {
                // The claim becomes a real movement, and only now does an entry
                // exist — because only now has value actually left.
                $captureContext = new PostingContext(
                    source: $locked->source,
                    description: $locked->description,
                    idempotencyKey: $locked->idempotency_key === null
                        ? null
                        : $locked->idempotency_key.':capture',
                    actorId: $locked->created_by,
                    paymentId: $locked->payment_id,
                    userId: $locked->user_id,
                );

                $entry = $this->writeEntriesFor($wallet, $locked, $captureContext, false, $before, $platform, $withdrawable)[0];
            }

            /*
             * The other end of the claim, appended rather than overwritten.
             * `wallet_transactions.status` now says the reservation is gone;
             * only this says it was ever there, when it ended, and how.
             */
            $this->recordEvent($wallet, $locked, new PostingContext(
                source: $locked->source,
                description: $locked->description,
                idempotencyKey: $locked->idempotency_key,
                actorId: $locked->created_by,
            ), [
                'from_status' => $from,
                'to_status' => $to,
                'bucket' => $bucket,
                'bucket_before' => $bucketBefore,
                'bucket_after' => $wallet->{$bucket},
                'total_before' => $before,
                'total_after' => $wallet->total,
                'ledger_entry_id' => $entry?->id,
            ]);

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

    /**
     * The same posting context under a different key: a conversion is two
     * postings, and each is idempotent on its own.
     */
    protected function contextWithKey(PostingContext $context, string $key): PostingContext
    {
        return new PostingContext(
            source: $context->source,
            description: $context->description,
            idempotencyKey: $key,
            reason: $context->reason,
            internalNote: $context->internalNote,
            actorId: $context->actorId,
            approvedBy: $context->approvedBy,
            paymentId: $context->paymentId,
            userPackageId: $context->userPackageId,
            userId: $context->userId,
            direction: $context->direction,
            correctsLedgerEntryId: $context->correctsLedgerEntryId,
        );
    }

    /**
     * @param  Money|null  $platformCredit  how much of the posting was platform credit; the rest is withdrawable
     */
    protected function openTransaction(
        Wallet $wallet,
        LedgerTransactionType $type,
        Money $amount,
        PostingContext $context,
        LedgerDirection $direction,
        WalletTransactionStatus $status,
        ?Money $platformCredit = null,
        ?string $conversionReference = null,
    ): WalletTransaction {
        return WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'business_account_id' => $wallet->business_account_id,
            'user_id' => $context->userId,
            'type' => $type,
            'direction' => $direction,
            'source' => $context->source,
            'amount' => $amount,
            'platform_credit_amount' => $platformCredit ?? Money::zero($amount->currency),
            'conversion_reference' => $conversionReference,
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
     * Append what just happened to this transaction (§23.3).
     *
     * Written in the same transaction as the change it records, so a rolled-back
     * posting leaves no event claiming otherwise. There is no idempotency check
     * here and there does not need to be: the state machine and the unique key
     * already make each change happen once, and this only ever runs after one
     * has.
     *
     * @param  array<string, mixed>  $state
     */
    protected function recordEvent(
        Wallet $wallet,
        WalletTransaction $transaction,
        PostingContext $context,
        array $state,
    ): WalletTransactionEvent {
        return WalletTransactionEvent::create([
            'wallet_transaction_id' => $transaction->id,
            'wallet_id' => $wallet->id,
            'business_account_id' => $wallet->business_account_id,
            'amount' => $transaction->amount,
            'currency_code' => $transaction->currency_code,
            'source' => $context->source,
            'actor_id' => $context->actorId,
            'reason' => $context->reason,
            'idempotency_key' => $context->idempotencyKey,
            'occurred_at' => now(),

            ...$state,
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
        BalanceType $balanceType = BalanceType::Withdrawable,
        ?string $idempotencyKey = null,
        ?string $conversionReference = null,
    ): LedgerEntry {
        return LedgerEntry::create([
            'wallet_id' => $wallet->id,
            'business_account_id' => $wallet->business_account_id,
            'user_id' => $context->userId,
            'wallet_transaction_id' => $transaction->id,
            'type' => $transaction->type,
            'balance_type' => $balanceType,
            'conversion_reference' => $conversionReference,
            'source' => $context->source,
            'payment_id' => $context->paymentId,
            'user_package_id' => $context->userPackageId,
            'currency_code' => $wallet->currency_code,

            ...$amounts,

            // What every bucket held immediately after this entry (§23.2), so a
            // statement from last March reads without reconstructing March.
            'pending' => $wallet->pending,
            'reserved' => $wallet->reserved,
            'hold' => $wallet->hold,
            'available' => $wallet->usableBalance(),

            'status' => $transaction->status,
            'created_by' => $context->actorId,
            'approved_by' => $context->approvedBy,
            'description' => $context->description,
            'internal_note' => $context->internalNote,
            'idempotency_key' => $idempotencyKey,
            'corrects_ledger_entry_id' => $context->correctsLedgerEntryId,
            'created_at' => now(),
        ]);
    }
}
