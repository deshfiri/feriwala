<?php

namespace App\Domain\Supplier;

use App\Domain\Supplier\Data\SupplierPostingContext;
use App\Domain\Supplier\Enums\SupplierLedgerEntryType;
use App\Domain\Supplier\Exceptions\SupplierWalletOperationRefused;
use App\Domain\Supplier\Models\SupplierLedgerEntry;
use App\Domain\Supplier\Models\SupplierWallet;
use App\Domain\Wallet\WalletService;
use App\Support\Money\Money;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The only thing that moves money in a Supplier wallet (D25, P13-23/P13-24).
 *
 * Mirrors {@see WalletService}: every balance change goes
 * through here, every one writes its immutable {@see SupplierLedgerEntry} in
 * the **same transaction** as the row it explains, every write runs under
 * `lockForUpdate`, and every write is idempotent by key.
 *
 * A Supplier wallet has three buckets, not the original's six, so this has
 * fewer operations too:
 *
 *   - {@see credit()} — a payable settles; `total` rises.
 *   - {@see debitForReversal()} — a settled payable is reversed (P13-25);
 *     `total` falls by whatever is available and `recovery` rises by
 *     whatever is not, **in one entry** — never a negative available balance,
 *     never an unrecorded shortfall.
 *   - {@see reserve()} / {@see release()} — a withdrawal request sets money
 *     aside and, if rejected or failed, gives it back. Unlike the original's
 *     hold/reserve, **these do write a ledger entry**: P13-24 asks for one at
 *     request time, not only a bucket move nobody can see afterwards.
 *   - {@see pay()} — an approved withdrawal is paid; `total` and `reserved`
 *     fall together, exactly once.
 *   - {@see adjust()} — a manual correction, for whatever the above do not
 *     name; it is the only operation whose direction is not fixed.
 */
class SupplierWalletService
{
    public function __construct(
        protected DatabaseManager $database,
    ) {}

    public function credit(SupplierWallet $wallet, Money $amount, SupplierPostingContext $context): SupplierLedgerEntry
    {
        $this->assertPostable(SupplierLedgerEntryType::SettlementCredit, $context);
        $this->assertCurrency($wallet, $amount);

        if (! $amount->isPositive()) {
            throw SupplierWalletOperationRefused::notPositive();
        }

        return $this->withLockedWallet($wallet, $context->idempotencyKey, function (SupplierWallet $locked) use ($amount, $context) {
            return $this->write(
                $locked, SupplierLedgerEntryType::SettlementCredit, $context,
                debit: Money::zero($amount->currency),
                credit: $amount,
                reservedDelta: Money::zero($amount->currency),
                recoveryDelta: Money::zero($amount->currency),
            );
        });
    }

    /**
     * A settled payable is reversed (P13-25). Debits whatever is available
     * and raises `recovery` by the rest, in the one entry — the wallet is
     * locked before either figure is read, so a withdrawal racing this call
     * sees one consistent available balance, never a half-updated one.
     */
    public function debitForReversal(SupplierWallet $wallet, Money $amount, SupplierPostingContext $context): SupplierLedgerEntry
    {
        $this->assertPostable(SupplierLedgerEntryType::PayableReversalDebit, $context);
        $this->assertCurrency($wallet, $amount);

        if (! $amount->isPositive()) {
            throw SupplierWalletOperationRefused::notPositive();
        }

        return $this->withLockedWallet($wallet, $context->idempotencyKey, function (SupplierWallet $locked) use ($amount, $context) {
            $available = $locked->availableBalance();
            $toDebit = $amount->lessThanOrEqualTo($available) ? $amount : $available;
            $toRecover = $amount->minus($toDebit);

            return $this->write(
                $locked, SupplierLedgerEntryType::PayableReversalDebit, $context,
                debit: $toDebit,
                credit: Money::zero($amount->currency),
                reservedDelta: Money::zero($amount->currency),
                recoveryDelta: $toRecover,
            );
        });
    }

    /**
     * The Delivery Success Fee, charged the moment the order reaches
     * Delivered — independent of whether the payable itself has settled, so
     * the wallet may not yet hold enough. Mirrors {@see debitForReversal()}'s
     * available/recovery split for exactly that reason: debits whatever is
     * available and raises `recovery` by the rest, never a negative balance,
     * never a refused delivery confirmation (D-new).
     */
    public function debitFee(SupplierWallet $wallet, Money $amount, SupplierPostingContext $context): SupplierLedgerEntry
    {
        $this->assertPostable(SupplierLedgerEntryType::DeliverySuccessFeeDebit, $context);
        $this->assertCurrency($wallet, $amount);

        if (! $amount->isPositive()) {
            throw SupplierWalletOperationRefused::notPositive();
        }

        return $this->withLockedWallet($wallet, $context->idempotencyKey, function (SupplierWallet $locked) use ($amount, $context) {
            $available = $locked->availableBalance();
            $toDebit = $amount->lessThanOrEqualTo($available) ? $amount : $available;
            $toRecover = $amount->minus($toDebit);

            return $this->write(
                $locked, SupplierLedgerEntryType::DeliverySuccessFeeDebit, $context,
                debit: $toDebit,
                credit: Money::zero($amount->currency),
                reservedDelta: Money::zero($amount->currency),
                recoveryDelta: $toRecover,
            );
        });
    }

    /**
     * A withdrawal request sets money aside. Refused when less than the
     * amount is available — checked under the lock, not before it.
     */
    public function reserve(SupplierWallet $wallet, Money $amount, SupplierPostingContext $context): SupplierLedgerEntry
    {
        $this->assertPostable(SupplierLedgerEntryType::WithdrawalReserved, $context);
        $this->assertCurrency($wallet, $amount);

        if (! $amount->isPositive()) {
            throw SupplierWalletOperationRefused::notPositive();
        }

        return $this->withLockedWallet($wallet, $context->idempotencyKey, function (SupplierWallet $locked) use ($amount, $context) {
            if ($locked->availableBalance()->lessThan($amount)) {
                throw SupplierWalletOperationRefused::insufficientBalance($amount, $locked->availableBalance());
            }

            return $this->write(
                $locked, SupplierLedgerEntryType::WithdrawalReserved, $context,
                debit: Money::zero($amount->currency),
                credit: Money::zero($amount->currency),
                reservedDelta: $amount,
                recoveryDelta: Money::zero($amount->currency),
            );
        });
    }

    /**
     * A rejected or failed withdrawal gives its reservation back.
     */
    public function release(SupplierWallet $wallet, Money $amount, SupplierPostingContext $context): SupplierLedgerEntry
    {
        $this->assertPostable(SupplierLedgerEntryType::WithdrawalReservationReleased, $context);
        $this->assertCurrency($wallet, $amount);

        if (! $amount->isPositive()) {
            throw SupplierWalletOperationRefused::notPositive();
        }

        return $this->withLockedWallet($wallet, $context->idempotencyKey, function (SupplierWallet $locked) use ($amount, $context) {
            return $this->write(
                $locked, SupplierLedgerEntryType::WithdrawalReservationReleased, $context,
                debit: Money::zero($amount->currency),
                credit: Money::zero($amount->currency),
                reservedDelta: $amount->negated(),
                recoveryDelta: Money::zero($amount->currency),
            );
        });
    }

    /**
     * An approved withdrawal is paid: the reservation becomes a real debit,
     * both buckets falling together in the one entry.
     */
    public function pay(SupplierWallet $wallet, Money $amount, SupplierPostingContext $context): SupplierLedgerEntry
    {
        $this->assertPostable(SupplierLedgerEntryType::WithdrawalPaidDebit, $context);
        $this->assertCurrency($wallet, $amount);

        if (! $amount->isPositive()) {
            throw SupplierWalletOperationRefused::notPositive();
        }

        return $this->withLockedWallet($wallet, $context->idempotencyKey, function (SupplierWallet $locked) use ($amount, $context) {
            return $this->write(
                $locked, SupplierLedgerEntryType::WithdrawalPaidDebit, $context,
                debit: $amount,
                credit: Money::zero($amount->currency),
                reservedDelta: $amount->negated(),
                recoveryDelta: Money::zero($amount->currency),
            );
        });
    }

    /**
     * A correction made by a person, not a workflow. The only operation whose
     * direction the caller must state.
     */
    public function adjust(SupplierWallet $wallet, Money $amount, SupplierPostingContext $context): SupplierLedgerEntry
    {
        $this->assertPostable(SupplierLedgerEntryType::ManualAdjustment, $context);
        $this->assertCurrency($wallet, $amount);

        if (! $amount->isPositive()) {
            throw SupplierWalletOperationRefused::notPositive();
        }

        $direction = $context->direction
            ?? throw SupplierWalletOperationRefused::directionRequired(SupplierLedgerEntryType::ManualAdjustment);

        return $this->withLockedWallet($wallet, $context->idempotencyKey, function (SupplierWallet $locked) use ($amount, $context, $direction) {
            if (! $direction->isCredit() && $locked->availableBalance()->lessThan($amount)) {
                throw SupplierWalletOperationRefused::insufficientBalance($amount, $locked->availableBalance());
            }

            return $this->write(
                $locked, SupplierLedgerEntryType::ManualAdjustment, $context,
                debit: $direction->isCredit() ? Money::zero($amount->currency) : $amount,
                credit: $direction->isCredit() ? $amount : Money::zero($amount->currency),
                reservedDelta: Money::zero($amount->currency),
                recoveryDelta: Money::zero($amount->currency),
            );
        });
    }

    /**
     * Lock the wallet, run the posting, and make the whole thing idempotent
     * by key — the one place every public method above shares.
     *
     * @param  callable(SupplierWallet): SupplierLedgerEntry  $work
     */
    protected function withLockedWallet(SupplierWallet $wallet, ?string $idempotencyKey, callable $work): SupplierLedgerEntry
    {
        if ($idempotencyKey !== null && ($existing = $this->findByKey($idempotencyKey))) {
            return $existing;
        }

        try {
            return $this->database->transaction(function () use ($wallet, $work) {
                /** @var SupplierWallet $locked */
                $locked = SupplierWallet::query()->lockForUpdate()->findOrFail($wallet->id);

                $entry = $work($locked);

                $wallet->setRawAttributes($locked->getAttributes(), sync: true);

                return $entry;
            });
        } catch (UniqueConstraintViolationException $exception) {
            /*
             * Two identical commands raced and the unique index settled it.
             * The whole transaction rolled back, so nothing is half-written;
             * the winner's entry is the answer to both.
             */
            $existing = $idempotencyKey === null ? null : $this->findByKey($idempotencyKey);

            if ($existing === null) {
                throw $exception;
            }

            return $existing;
        }
    }

    protected function write(
        SupplierWallet $locked,
        SupplierLedgerEntryType $type,
        SupplierPostingContext $context,
        Money $debit,
        Money $credit,
        Money $reservedDelta,
        Money $recoveryDelta,
    ): SupplierLedgerEntry {
        $balanceBefore = $locked->total;
        $reservedBefore = $locked->reserved;
        $recoveryBefore = $locked->recovery;

        $balanceAfter = $balanceBefore->plus($credit)->minus($debit);
        $reservedAfter = $reservedBefore->plus($reservedDelta);
        $recoveryAfter = $recoveryBefore->plus($recoveryDelta);

        $locked->forceFill([
            'total' => $balanceAfter,
            'reserved' => $reservedAfter,
            'recovery' => $recoveryAfter,
        ])->save();

        return SupplierLedgerEntry::create([
            'supplier_wallet_id' => $locked->id,
            'supplier_id' => $locked->supplier_id,
            'type' => $type,
            'source' => $context->source,
            'supplier_payable_id' => $context->supplierPayableId,
            'supplier_payable_reversal_id' => $context->supplierPayableReversalId,
            'supplier_withdrawal_id' => $context->supplierWithdrawalId,
            'currency_code' => $locked->currency_code,
            'debit' => $debit,
            'credit' => $credit,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'reserved_before' => $reservedBefore,
            'reserved_after' => $reservedAfter,
            'recovery_before' => $recoveryBefore,
            'recovery_after' => $recoveryAfter,
            'description' => $context->description,
            'internal_note' => $context->internalNote,
            'created_by' => $context->actorId,
            'idempotency_key' => $context->idempotencyKey,
            'corrects_ledger_entry_id' => $context->correctsLedgerEntryId,
            'created_at' => now(),
        ]);
    }

    protected function assertPostable(SupplierLedgerEntryType $type, SupplierPostingContext $context): void
    {
        if ($type->requiresReason() && blank($context->reason)) {
            throw SupplierWalletOperationRefused::reasonRequired($type);
        }

        if ($type->requiresActor() && $context->actorId === null) {
            throw SupplierWalletOperationRefused::actorRequired($type);
        }
    }

    protected function assertCurrency(SupplierWallet $wallet, Money $amount): void
    {
        if ($amount->currency->value !== $wallet->currency_code) {
            throw SupplierWalletOperationRefused::currencyMismatch($wallet->currency_code, $amount->currency->value);
        }
    }

    protected function findByKey(?string $key): ?SupplierLedgerEntry
    {
        return $key === null
            ? null
            : SupplierLedgerEntry::query()->where('idempotency_key', $key)->first();
    }
}
