<?php

namespace App\Domain\Supplier\Actions;

use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Supplier\Data\SupplierPostingContext;
use App\Domain\Supplier\Enums\SupplierWithdrawalChangeSource;
use App\Domain\Supplier\Enums\SupplierWithdrawalStatus;
use App\Domain\Supplier\Exceptions\SupplierWithdrawalRefused;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierPayoutMethod;
use App\Domain\Supplier\Models\SupplierWithdrawal;
use App\Domain\Supplier\SupplierWalletService;
use App\Domain\Supplier\SupplierWithdrawalLimits;
use App\Notifications\Supplier\SupplierWithdrawalStatusChanged;
use App\Support\Idempotency\IdempotencyService;
use App\Support\Money\Money;
use App\Support\StatusHistory\StatusChange;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * A Supplier asks to withdraw from their own available balance (D25, P13-24).
 *
 * `$idempotencyKey` must be stable across a retry of the *same* submission —
 * the controller reads it from an `Idempotency-Key` header or an
 * equivalent client-generated token, the same durable, unique-index-backed
 * pattern {@see ReverseSupplierPayable} already
 * uses, rather than the cache-backed {@see IdempotencyService}
 * built for externally triggered callbacks with a bounded replay window: a
 * withdrawal request's identity should not expire after 24 hours.
 *
 * Everything happens in one transaction: the payout method is snapshotted so
 * a later edit or archive can never change what this withdrawal says it was
 * paid to, the wallet is locked and the amount reserved before the row is
 * even visible, and the first status-history row is written alongside it —
 * there is no moment where the withdrawal exists without its reservation or
 * its history.
 */
class RequestSupplierWithdrawal
{
    public function __construct(
        protected SupplierWalletService $wallets,
        protected OpenSupplierWallet $openWallet,
        protected SupplierWithdrawalLimits $limits,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    public function handle(
        Supplier $supplier,
        SupplierPayoutMethod $payoutMethod,
        Money $amount,
        string $idempotencyKey,
    ): SupplierWithdrawal {
        if (! $supplier->isOperational()) {
            throw SupplierWithdrawalRefused::supplierNotOperational();
        }

        if ($payoutMethod->supplier_id !== $supplier->id || ! $payoutMethod->isActive()) {
            throw SupplierWithdrawalRefused::payoutMethodNotUsable();
        }

        $minimum = $this->limits->minimumFor($supplier, $amount->currency);

        if ($amount->lessThan($minimum)) {
            throw SupplierWithdrawalRefused::belowMinimum($amount, $minimum);
        }

        $maximum = $this->limits->maximumFor($supplier, $amount->currency);

        if ($maximum !== null && $amount->greaterThan($maximum)) {
            throw SupplierWithdrawalRefused::aboveMaximum($amount, $maximum);
        }

        $existing = SupplierWithdrawal::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return $this->database->transaction(function () use ($supplier, $payoutMethod, $amount, $idempotencyKey) {
                $wallet = $this->openWallet->handle($supplier, $amount->currency);

                $withdrawal = SupplierWithdrawal::create([
                    'supplier_id' => $supplier->id,
                    'supplier_wallet_id' => $wallet->id,
                    'supplier_payout_method_id' => $payoutMethod->id,
                    'payout_snapshot' => $payoutMethod->toSnapshot(),
                    'amount' => $amount,
                    'currency_code' => $amount->currency->value,
                    'status' => SupplierWithdrawalStatus::Requested,
                    'requested_at' => now(),
                    'idempotency_key' => $idempotencyKey,
                ]);

                $this->wallets->reserve($wallet, $amount, new SupplierPostingContext(
                    source: 'withdrawal',
                    description: "Reservation for withdrawal {$withdrawal->reference}",
                    idempotencyKey: 'supplier-withdrawal-reserve:'.$withdrawal->public_id,
                    supplierWithdrawalId: $withdrawal->id,
                ));

                $withdrawal->recordStatusChange(
                    null,
                    SupplierWithdrawalStatus::Requested,
                    new StatusChange,
                    ['source' => SupplierWithdrawalChangeSource::Supplier],
                );

                $this->audit->handle(new AuditEntry(
                    action: 'supplier_withdrawal.requested',
                    auditableType: SupplierWithdrawal::class,
                    auditableId: $withdrawal->id,
                    after: ['amount' => $amount->toDecimal(), 'currency' => $amount->currency->value],
                    accountId: $supplier->id,
                    module: PermissionModule::Withdrawal->value,
                ));

                $supplier->notify(
                    (new SupplierWithdrawalStatusChanged($withdrawal))->locale($supplier->locale)
                );

                return $withdrawal;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $racedWith = SupplierWithdrawal::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($racedWith === null) {
                throw $exception;
            }

            return $racedWith;
        }
    }
}
