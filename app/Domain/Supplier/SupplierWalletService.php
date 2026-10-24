<?php

namespace App\Domain\Supplier;

use App\Domain\Supplier\Data\SupplierPostingContext;
use App\Domain\Supplier\Enums\SupplierLedgerEntryType;
use App\Domain\Supplier\Exceptions\SupplierWalletOperationRefused;
use App\Domain\Supplier\Models\SupplierLedgerEntry;
use App\Domain\Supplier\Models\SupplierWallet;
use App\Domain\Wallet\Enums\BalanceType;
use App\Domain\Wallet\WalletService;
use App\Support\Money\Money;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use InvalidArgumentException;

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
 *   - {@see convertToWithdrawable()} — platform credit becomes withdrawable.
 *
 * **Two kinds of money**, exactly as in {@see WalletService}: every entry says
 * whether it moved platform credit or withdrawable money. A Supplier's
 * earnings are withdrawable; a debit inside the platform (a fee, a reversal)
 * spends platform credit first, and when it takes both kinds it writes one
 * entry for each, under keys that carry the command's own as a prefix. A
 * withdrawal's reservation is withdrawable money and nothing else.
 */
class SupplierWalletService
{
    public function __construct(
        protected DatabaseManager $database,
    ) {}

    /**
     * A payable settles, or money is credited by hand.
     *
     * `$balanceType` is the kind of money it is; left null it is the kind the
     * entry type maps to — a settlement is withdrawable — and a type that maps
     * to nothing is refused rather than guessed at.
     */
    public function credit(
        SupplierWallet $wallet,
        Money $amount,
        SupplierPostingContext $context,
        ?BalanceType $balanceType = null,
    ): SupplierLedgerEntry {
        $type = SupplierLedgerEntryType::SettlementCredit;

        $this->assertPostable($type, $context);
        $this->assertCurrency($wallet, $amount);

        if (! $amount->isPositive()) {
            throw SupplierWalletOperationRefused::notPositive();
        }

        // What a Supplier earns is withdrawable unless the caller says otherwise.
        $balanceType ??= $type->creditBalanceType() ?? BalanceType::Withdrawable;

        return $this->withLockedWallet($wallet, $context->idempotencyKey, function (SupplierWallet $locked) use ($amount, $context, $type, $balanceType) {
            return $this->write(
                $locked, $type, $context,
                debit: Money::zero($amount->currency),
                credit: $amount,
                reservedDelta: Money::zero($amount->currency),
                recoveryDelta: Money::zero($amount->currency),
                balanceType: $balanceType,
            );
        });
    }

    /**
     * A settled payable is reversed (P13-25). Debits whatever is available and
     * raises `recovery` by the rest, in the one entry — the wallet is
     * locked before either figure is read, so a withdrawal racing this call
     * sees one consistent available balance, never a half-updated one.
     *
     * Platform credit is spent first; when both kinds are taken the first
     * entry carries the platform-credit part and the last carries the recovery.
     * The entry returned is the first; {@see SupplierLedgerEntry::scopeForCommand()}
     * reads them all.
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

            return $this->writeDebit($locked, SupplierLedgerEntryType::PayableReversalDebit, $context, $toDebit, $toRecover);
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

            return $this->writeDebit($locked, SupplierLedgerEntryType::DeliverySuccessFeeDebit, $context, $toDebit, $toRecover);
        });
    }

    /**
     * A reservation sets money aside — a withdrawal's claim, or any other
     * claim on the wallet. It takes **one kind** of money, and the entry says
     * which: `$from` names it, and a withdrawal (the default) is withdrawable
     * money, so platform credit can never be claimed by one. Refused when less
     * than the amount of that kind is available — checked under the lock, not
     * before it, and never made up from the other kind.
     *
     * Releasing and paying a reservation use the kind it was made from.
     */
    public function reserve(SupplierWallet $wallet, Money $amount, SupplierPostingContext $context, ?BalanceType $from = null): SupplierLedgerEntry
    {
        $this->assertPostable(SupplierLedgerEntryType::WithdrawalReserved, $context);
        $this->assertCurrency($wallet, $amount);

        if (! $amount->isPositive()) {
            throw SupplierWalletOperationRefused::notPositive();
        }

        $from ??= BalanceType::Withdrawable;

        return $this->withLockedWallet($wallet, $context->idempotencyKey, function (SupplierWallet $locked) use ($amount, $context, $from) {
            $available = $from->isPlatformCredit() ? $locked->platformCreditAvailable() : $locked->availableForWithdrawal();

            if ($available->lessThan($amount)) {
                throw SupplierWalletOperationRefused::insufficientBalance($amount, $available);
            }

            return $this->write(
                $locked, SupplierLedgerEntryType::WithdrawalReserved, $context,
                debit: Money::zero($amount->currency),
                credit: Money::zero($amount->currency),
                reservedDelta: $amount,
                recoveryDelta: Money::zero($amount->currency),
                balanceType: $from,
                reservedPlatformDelta: $from->isPlatformCredit() ? $amount : Money::zero($amount->currency),
            );
        });
    }

    /**
     * A rejected or failed reservation gives its money back — to the kind it
     * was taken from, never the other.
     */
    public function release(SupplierWallet $wallet, Money $amount, SupplierPostingContext $context, ?BalanceType $from = null): SupplierLedgerEntry
    {
        $this->assertPostable(SupplierLedgerEntryType::WithdrawalReservationReleased, $context);
        $this->assertCurrency($wallet, $amount);

        if (! $amount->isPositive()) {
            throw SupplierWalletOperationRefused::notPositive();
        }

        $from ??= $this->reservedKind($context);

        return $this->withLockedWallet($wallet, $context->idempotencyKey, function (SupplierWallet $locked) use ($amount, $context, $from) {
            return $this->write(
                $locked, SupplierLedgerEntryType::WithdrawalReservationReleased, $context,
                debit: Money::zero($amount->currency),
                credit: Money::zero($amount->currency),
                reservedDelta: $amount->negated(),
                recoveryDelta: Money::zero($amount->currency),
                balanceType: $from,
                reservedPlatformDelta: $from->isPlatformCredit() ? $amount->negated() : Money::zero($amount->currency),
            );
        });
    }

    /**
     * A reservation is paid: it becomes a real debit, both buckets falling
     * together in the one entry — out of the kind of money it was taken from.
     * A withdrawal is withdrawable money that leaves; platform credit is not
     * touched.
     */
    public function pay(SupplierWallet $wallet, Money $amount, SupplierPostingContext $context, ?BalanceType $from = null): SupplierLedgerEntry
    {
        $this->assertPostable(SupplierLedgerEntryType::WithdrawalPaidDebit, $context);
        $this->assertCurrency($wallet, $amount);

        if (! $amount->isPositive()) {
            throw SupplierWalletOperationRefused::notPositive();
        }

        $from ??= $this->reservedKind($context);

        return $this->withLockedWallet($wallet, $context->idempotencyKey, function (SupplierWallet $locked) use ($amount, $context, $from) {
            return $this->write(
                $locked, SupplierLedgerEntryType::WithdrawalPaidDebit, $context,
                debit: $amount,
                credit: Money::zero($amount->currency),
                reservedDelta: $amount->negated(),
                recoveryDelta: Money::zero($amount->currency),
                balanceType: $from,
                platformCreditAfter: $from->isPlatformCredit() ? $locked->platformCredit()->minus($amount) : null,
                reservedPlatformDelta: $from->isPlatformCredit() ? $amount->negated() : Money::zero($amount->currency),
            );
        });
    }

    /**
     * The kind of money a withdrawal's reservation was taken from: what its own
     * reserving entry recorded, and withdrawable money where there is none (a
     * reservation made before typed balances existed).
     */
    protected function reservedKind(SupplierPostingContext $context): BalanceType
    {
        if ($context->supplierWithdrawalId === null) {
            return BalanceType::Withdrawable;
        }

        $reserving = SupplierLedgerEntry::query()
            ->where('supplier_withdrawal_id', $context->supplierWithdrawalId)
            ->where('type', SupplierLedgerEntryType::WithdrawalReserved)
            ->orderBy('id')
            ->first();

        return $reserving === null ? BalanceType::Withdrawable : $reserving->balance_type;
    }

    /**
     * A correction made by a person, not a workflow. The only operation whose
     * direction the caller must state.
     *
     * A credit must say which kind of money it is — a manual top-up is a
     * decision about whether the money can leave the platform, and is never
     * defaulted. A debit may name the kind to take first.
     */
    public function adjust(
        SupplierWallet $wallet,
        Money $amount,
        SupplierPostingContext $context,
        ?BalanceType $balanceType = null,
    ): SupplierLedgerEntry {
        $this->assertPostable(SupplierLedgerEntryType::ManualAdjustment, $context);
        $this->assertCurrency($wallet, $amount);

        if (! $amount->isPositive()) {
            throw SupplierWalletOperationRefused::notPositive();
        }

        $direction = $context->direction
            ?? throw SupplierWalletOperationRefused::directionRequired(SupplierLedgerEntryType::ManualAdjustment);

        if ($direction->isCredit() && $balanceType === null) {
            throw SupplierWalletOperationRefused::balanceTypeRequired(SupplierLedgerEntryType::ManualAdjustment);
        }

        return $this->withLockedWallet($wallet, $context->idempotencyKey, function (SupplierWallet $locked) use ($amount, $context, $direction, $balanceType) {
            if (! $direction->isCredit() && $locked->availableBalance()->lessThan($amount)) {
                throw SupplierWalletOperationRefused::insufficientBalance($amount, $locked->availableBalance());
            }

            if (! $direction->isCredit()) {
                return $this->writeDebit($locked, SupplierLedgerEntryType::ManualAdjustment, $context, $amount, Money::zero($amount->currency), $balanceType);
            }

            return $this->write(
                $locked, SupplierLedgerEntryType::ManualAdjustment, $context,
                debit: Money::zero($amount->currency),
                credit: $amount,
                reservedDelta: Money::zero($amount->currency),
                recoveryDelta: Money::zero($amount->currency),
                balanceType: $balanceType ?? throw SupplierWalletOperationRefused::balanceTypeRequired(SupplierLedgerEntryType::ManualAdjustment),
            );
        });
    }

    /**
     * Turn platform credit into withdrawable money.
     *
     * The total does not change: a debit entry takes the amount out of platform
     * credit and a credit entry puts it into withdrawable money, in one
     * transaction, sharing a conversion reference. Refused when less than the
     * amount is available as platform credit, and idempotent on the context's
     * key, which is required.
     *
     * @return array{debit: SupplierLedgerEntry, credit: SupplierLedgerEntry}
     */
    public function convertToWithdrawable(SupplierWallet $wallet, Money $amount, SupplierPostingContext $context): array
    {
        if ($context->idempotencyKey === null) {
            throw new InvalidArgumentException('A balance conversion needs an idempotency key.');
        }

        $this->assertPostable(SupplierLedgerEntryType::BalanceConversionDebit, $context);
        $this->assertCurrency($wallet, $amount);

        if (! $amount->isPositive()) {
            throw SupplierWalletOperationRefused::notPositive();
        }

        $debitKey = $context->idempotencyKey.':debit';
        $creditKey = $context->idempotencyKey.':credit';

        if ($existing = $this->findByKey($debitKey)) {
            return ['debit' => $existing, 'credit' => $this->findByKey($creditKey) ?? $existing];
        }

        try {
            return $this->database->transaction(function () use ($wallet, $amount, $context, $debitKey, $creditKey) {
                /** @var SupplierWallet $locked */
                $locked = SupplierWallet::query()->lockForUpdate()->findOrFail($wallet->id);

                $platform = $locked->platformCredit();
                $available = $locked->platformCreditAvailable();

                if ($amount->greaterThan($available)) {
                    throw SupplierWalletOperationRefused::insufficientPlatformCredit($amount, $available);
                }

                $reference = (string) Str::ulid();
                $zero = Money::zero($amount->currency);

                $out = $this->write(
                    $locked, SupplierLedgerEntryType::BalanceConversionDebit, $this->contextWithKey($context, $debitKey),
                    debit: $amount, credit: $zero, reservedDelta: $zero, recoveryDelta: $zero,
                    balanceType: BalanceType::PlatformCredit,
                    platformCreditAfter: $platform->minus($amount),
                    conversionReference: $reference,
                );

                $in = $this->write(
                    $locked, SupplierLedgerEntryType::BalanceConversionCredit, $this->contextWithKey($context, $creditKey),
                    debit: $zero, credit: $amount, reservedDelta: $zero, recoveryDelta: $zero,
                    balanceType: BalanceType::Withdrawable,
                    platformCreditAfter: $platform->minus($amount),
                    conversionReference: $reference,
                );

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
     * Lock the wallet, run the posting, and make the whole thing idempotent
     * by key — the one place every public method above shares.
     *
     * @param  callable(SupplierWallet): SupplierLedgerEntry  $work
     */
    protected function withLockedWallet(SupplierWallet $wallet, ?string $idempotencyKey, callable $work): SupplierLedgerEntry
    {
        if ($idempotencyKey !== null && ($existing = $this->findByKey($idempotencyKey) ?? $this->findFirstOfSplit($idempotencyKey))) {
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
            $existing = $idempotencyKey === null
                ? null
                : ($this->findByKey($idempotencyKey) ?? $this->findFirstOfSplit($idempotencyKey));

            if ($existing === null) {
                throw $exception;
            }

            return $existing;
        }
    }

    /**
     * A debit inside the platform: platform credit first, withdrawable money
     * for the rest, one entry for each kind used. The recovery that could not be
     * debited rides on the last entry, so the whole command still reads as the
     * one thing it was.
     */
    protected function writeDebit(
        SupplierWallet $locked,
        SupplierLedgerEntryType $type,
        SupplierPostingContext $context,
        Money $toDebit,
        Money $toRecover,
        ?BalanceType $only = null,
    ): SupplierLedgerEntry {
        $zero = Money::zero($toDebit->currency);

        try {
            [$platform, $withdrawable] = $locked->allocateSpend($toDebit, $only);
        } catch (\LogicException) {
            throw SupplierWalletOperationRefused::insufficientBalance($toDebit, $only?->isPlatformCredit() === true ? $locked->platformCreditFree() : $locked->withdrawableFree());
        }

        $parts = [];

        if ($platform->isPositive()) {
            $parts[] = [BalanceType::PlatformCredit, $platform];
        }

        if ($withdrawable->isPositive() || $parts === []) {
            // Nothing debited at all still writes the one entry that records
            // the recovery, exactly as a single-kind posting always did.
            $parts[] = [BalanceType::Withdrawable, $withdrawable];
        }

        $split = count($parts) > 1;
        $platformCredit = $locked->platformCredit();
        $first = null;

        foreach ($parts as $index => [$kind, $part]) {
            $isLast = $index === count($parts) - 1;

            if ($kind->isPlatformCredit()) {
                $platformCredit = $platformCredit->minus($part);
            }

            $entry = $this->write(
                $locked, $type, $context,
                debit: $part,
                credit: $zero,
                reservedDelta: $zero,
                recoveryDelta: $isLast ? $toRecover : $zero,
                balanceType: $kind,
                key: $split && $context->idempotencyKey !== null ? $context->idempotencyKey.':'.$kind->value : $context->idempotencyKey,
                platformCreditAfter: $platformCredit,
            );

            $first ??= $entry;
        }

        /** @var SupplierLedgerEntry $first */
        return $first;
    }

    protected function write(
        SupplierWallet $locked,
        SupplierLedgerEntryType $type,
        SupplierPostingContext $context,
        Money $debit,
        Money $credit,
        Money $reservedDelta,
        Money $recoveryDelta,
        BalanceType $balanceType = BalanceType::Withdrawable,
        ?string $key = null,
        ?Money $platformCreditAfter = null,
        ?string $conversionReference = null,
        ?Money $reservedPlatformDelta = null,
    ): SupplierLedgerEntry {
        $balanceBefore = $locked->total;
        $reservedBefore = $locked->reserved;
        $recoveryBefore = $locked->recovery;

        $balanceAfter = $balanceBefore->plus($credit)->minus($debit);
        $reservedAfter = $reservedBefore->plus($reservedDelta);
        $recoveryAfter = $recoveryBefore->plus($recoveryDelta);

        // A platform-credit credit grows it; everything else leaves it where it
        // is unless the caller worked out where it ends up.
        $platformAfter = $platformCreditAfter
            ?? ($balanceType->isPlatformCredit() && $credit->isPositive()
                ? $locked->platformCredit()->plus($credit)
                : $locked->platformCredit());

        $locked->forceFill([
            'total' => $balanceAfter,
            'platform_credit' => $platformAfter,
            'reserved_platform_credit' => $reservedPlatformDelta === null
                ? $locked->reserved_platform_credit
                : $locked->reserved_platform_credit->plus($reservedPlatformDelta),
            'reserved' => $reservedAfter,
            'recovery' => $recoveryAfter,
        ])->save();

        return SupplierLedgerEntry::create([
            'supplier_wallet_id' => $locked->id,
            'supplier_id' => $locked->supplier_id,
            'type' => $type,
            'balance_type' => $balanceType,
            'conversion_reference' => $conversionReference,
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
            'idempotency_key' => $key ?? $context->idempotencyKey,
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

    /**
     * The first entry of a command that spent both kinds of money: its entries
     * carry the command's key as a prefix, so the key itself finds none.
     */
    protected function findFirstOfSplit(string $key): ?SupplierLedgerEntry
    {
        return SupplierLedgerEntry::query()->forCommand($key)->first();
    }

    /**
     * The same posting context under a different key: a conversion is two
     * postings, and each is idempotent on its own.
     */
    protected function contextWithKey(SupplierPostingContext $context, string $key): SupplierPostingContext
    {
        return new SupplierPostingContext(
            source: $context->source,
            description: $context->description,
            idempotencyKey: $key,
            reason: $context->reason,
            internalNote: $context->internalNote,
            actorId: $context->actorId,
            supplierPayableId: $context->supplierPayableId,
            supplierPayableReversalId: $context->supplierPayableReversalId,
            supplierWithdrawalId: $context->supplierWithdrawalId,
            direction: $context->direction,
            correctsLedgerEntryId: $context->correctsLedgerEntryId,
        );
    }
}
