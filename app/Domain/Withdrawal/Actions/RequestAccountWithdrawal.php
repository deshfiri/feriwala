<?php

namespace App\Domain\Withdrawal\Actions;

use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Kyc\KycRestrictions;
use App\Domain\Payout\Enums\PayoutOwnerType;
use App\Domain\Payout\Models\PayoutMethod;
use App\Domain\Supplier\Actions\RequestSupplierWithdrawal;
use App\Domain\Wallet\Data\PostingContext;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\WalletService;
use App\Domain\Withdrawal\AccountWithdrawalLimits;
use App\Domain\Withdrawal\Enums\AccountWithdrawalChangeSource;
use App\Domain\Withdrawal\Enums\AccountWithdrawalStatus;
use App\Domain\Withdrawal\Exceptions\AccountWithdrawalRefused;
use App\Domain\Withdrawal\Models\AccountWithdrawal;
use App\Support\Money\Money;
use App\Support\StatusHistory\StatusChange;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * A Client/Partner `BusinessAccount` asks to withdraw from its own wallet
 * (§27, D25 — mirrors {@see RequestSupplierWithdrawal}
 * for a different owner and a different underlying reservation primitive).
 *
 * `$idempotencyKey` must be stable across a retry of the *same* submission —
 * the controller reads it from a client-generated token, the same durable,
 * unique-index-backed pattern the Supplier side already uses, rather than
 * the cache-backed `IdempotencyService` built for externally triggered
 * callbacks with a bounded replay window.
 *
 * Everything happens in one transaction: the payout method is snapshotted
 * so a later edit or archive can never change what this withdrawal says it
 * was paid to, the wallet is locked and the amount reserved (via
 * {@see WalletService::reserve()}) before the row is even visible, and the
 * first status-history row is written alongside it.
 *
 * An outstanding KYC re-verification can block a *new* request (§7.4,
 * `.ai/rules/withdrawal.md`) — checked here, the same shape
 * `PlaceWholesaleOrder` uses for `blocksNewOrders()`. A withdrawal already
 * in flight is an existing obligation and is never cancelled by a KYC
 * consequence.
 */
class RequestAccountWithdrawal
{
    public function __construct(
        protected WalletService $wallets,
        protected AccountWithdrawalLimits $limits,
        protected KycRestrictions $kycRestrictions,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    public function handle(
        BusinessAccount $account,
        Wallet $wallet,
        PayoutMethod $payoutMethod,
        Money $amount,
        string $idempotencyKey,
    ): AccountWithdrawal {
        if (! $account->status->isActivated()) {
            throw AccountWithdrawalRefused::accountNotActive();
        }

        if ($this->kycRestrictions->blocksWithdrawals($account)) {
            throw AccountWithdrawalRefused::kycReverificationOutstanding(
                $this->kycRestrictions->refusalReason($account),
            );
        }

        if (! $payoutMethod->ownedBy(PayoutOwnerType::BusinessAccount, $account->id) || ! $payoutMethod->isActive()) {
            throw AccountWithdrawalRefused::payoutMethodNotUsable();
        }

        $minimum = $this->limits->minimumFor($account, $amount->currency);

        if ($amount->lessThan($minimum)) {
            throw AccountWithdrawalRefused::belowMinimum($amount, $minimum);
        }

        $maximum = $this->limits->maximumFor($account, $amount->currency);

        if ($maximum !== null && $amount->greaterThan($maximum)) {
            throw AccountWithdrawalRefused::aboveMaximum($amount, $maximum);
        }

        $existing = AccountWithdrawal::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return $this->database->transaction(function () use ($account, $wallet, $payoutMethod, $amount, $idempotencyKey) {
                // Locked and re-checked here, tighter than the generic
                // usableBalance() guard WalletService::reserve() applies on
                // its own: §24.2 keeps the required deposit out of reach of
                // a withdrawal even on a rule that lets it cover service
                // charges, and only this stricter figure knows that.
                $locked = Wallet::query()->lockForUpdate()->findOrFail($wallet->id);
                $available = $locked->availableForWithdrawal();

                if ($amount->greaterThan($available)) {
                    throw AccountWithdrawalRefused::insufficientBalance($amount, $available);
                }

                $claim = $this->wallets->reserve($wallet, LedgerTransactionType::WithdrawalDebit, $amount, new PostingContext(
                    source: 'withdrawal',
                    description: 'Reservation for a withdrawal request',
                    idempotencyKey: 'account-withdrawal-reserve:'.$idempotencyKey,
                    userId: $account->owner_id,
                ));

                $withdrawal = AccountWithdrawal::create([
                    'business_account_id' => $account->id,
                    'wallet_id' => $wallet->id,
                    'payout_method_id' => $payoutMethod->id,
                    'wallet_transaction_id' => $claim->id,
                    'payout_snapshot' => $payoutMethod->toSnapshot()->toArray(),
                    'amount' => $amount,
                    'currency_code' => $amount->currency->value,
                    'status' => AccountWithdrawalStatus::Requested,
                    'requested_at' => now(),
                    'idempotency_key' => $idempotencyKey,
                ]);

                $withdrawal->recordStatusChange(
                    null,
                    AccountWithdrawalStatus::Requested,
                    new StatusChange,
                    ['source' => AccountWithdrawalChangeSource::Account],
                );

                $this->audit->handle(new AuditEntry(
                    action: 'account_withdrawal.requested',
                    auditableType: AccountWithdrawal::class,
                    auditableId: $withdrawal->id,
                    after: ['amount' => $amount->toDecimal(), 'currency' => $amount->currency->value],
                    accountId: $account->id,
                    module: PermissionModule::Withdrawal->value,
                ));

                return $withdrawal;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $racedWith = AccountWithdrawal::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($racedWith === null) {
                throw $exception;
            }

            return $racedWith;
        }
    }
}
