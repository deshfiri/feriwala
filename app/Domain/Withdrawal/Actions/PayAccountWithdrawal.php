<?php

namespace App\Domain\Withdrawal\Actions;

use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Supplier\Actions\PaySupplierWithdrawal;
use App\Domain\Wallet\WalletService;
use App\Domain\Withdrawal\Enums\AccountWithdrawalChangeSource;
use App\Domain\Withdrawal\Enums\AccountWithdrawalStatus;
use App\Domain\Withdrawal\Exceptions\AccountWithdrawalRefused;
use App\Domain\Withdrawal\Models\AccountWithdrawal;
use App\Notifications\Account\AccountWithdrawalStatusChanged;
use App\Support\StatusHistory\StatusChange;
use Illuminate\Database\DatabaseManager;

/**
 * Pay a Processing withdrawal (§27, mirrors
 * {@see PaySupplierWithdrawal}).
 *
 * Consumes the reservation exactly once via
 * {@see WalletService::capture()} on the withdrawal's own claim: the
 * wallet's `reserved` and `total` fall together in one ledger entry, and the
 * claim's own state machine — settled inside a lock — is what stops a retry
 * from paying twice. Requires the external reference the payout channel
 * returned; the database itself refuses a `Paid` row without one
 * (`account_withdrawals_paid_is_complete`).
 */
class PayAccountWithdrawal
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
        protected WalletService $wallets,
    ) {}

    public function handle(
        AccountWithdrawal $withdrawal,
        string $externalReference,
        int $actorId,
    ): AccountWithdrawal {
        return $this->database->transaction(function () use ($withdrawal, $externalReference, $actorId) {
            /** @var AccountWithdrawal $locked */
            $locked = AccountWithdrawal::query()->lockForUpdate()->findOrFail($withdrawal->id);

            if (! $locked->canTransitionTo(AccountWithdrawalStatus::Paid)) {
                throw AccountWithdrawalRefused::illegalTransition($locked->reference, $locked->status->label());
            }

            $claim = $locked->walletTransaction()->firstOrFail();

            $this->wallets->capture($claim);

            // Left dirty rather than saved here: the database's own
            // `account_withdrawals_paid_is_complete` CHECK requires status
            // and these three columns to change together in one UPDATE, not
            // paid_at arriving one statement before status does.
            $locked->forceFill([
                'processed_by' => $actorId,
                'processed_at' => $locked->processed_at ?? now(),
                'paid_at' => now(),
                'external_reference' => $externalReference,
            ]);

            $locked->transitionWithHistory(
                AccountWithdrawalStatus::Paid,
                new StatusChange(actorId: $actorId),
                ['source' => AccountWithdrawalChangeSource::Staff],
            );

            $this->audit->handle(new AuditEntry(
                action: 'account_withdrawal.paid',
                actorId: $actorId,
                auditableType: AccountWithdrawal::class,
                auditableId: $locked->id,
                after: ['status' => 'paid', 'external_reference' => $externalReference],
                accountId: $locked->business_account_id,
                module: PermissionModule::Withdrawal->value,
                isSensitive: true,
            ));

            $locked->businessAccount->owner?->notify(new AccountWithdrawalStatusChanged($locked));

            return $locked;
        });
    }
}
