<?php

namespace App\Domain\Withdrawal\Actions;

use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Supplier\Actions\RejectOrFailSupplierWithdrawal;
use App\Domain\Wallet\WalletService;
use App\Domain\Withdrawal\Enums\AccountWithdrawalChangeSource;
use App\Domain\Withdrawal\Enums\AccountWithdrawalStatus;
use App\Domain\Withdrawal\Exceptions\AccountWithdrawalRefused;
use App\Domain\Withdrawal\Models\AccountWithdrawal;
use App\Notifications\Account\AccountWithdrawalStatusChanged;
use App\Support\StatusHistory\StatusChange;
use Illuminate\Database\DatabaseManager;

/**
 * Reject a requested withdrawal, or mark a processing one failed (§27,
 * mirrors {@see RejectOrFailSupplierWithdrawal}).
 *
 * Both release the reservation {@see RequestAccountWithdrawal} made — via
 * {@see WalletService::release()} on the withdrawal's own claim, exactly
 * once, because that claim's own state machine has no second move out of a
 * released transaction: a repeat finds the illegal move and refuses before
 * the wallet is ever touched again.
 */
class RejectOrFailAccountWithdrawal
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
        protected WalletService $wallets,
    ) {}

    public function handle(
        AccountWithdrawal $withdrawal,
        AccountWithdrawalStatus $to,
        string $reason,
        int $actorId,
    ): AccountWithdrawal {
        return $this->database->transaction(function () use ($withdrawal, $to, $reason, $actorId) {
            /** @var AccountWithdrawal $locked */
            $locked = AccountWithdrawal::query()->lockForUpdate()->findOrFail($withdrawal->id);

            if (! $locked->canTransitionTo($to)) {
                throw AccountWithdrawalRefused::illegalTransition($locked->reference, $locked->status->label());
            }

            $claim = $locked->walletTransaction()->firstOrFail();

            $this->wallets->release($claim);

            if ($to === AccountWithdrawalStatus::Failed) {
                $locked->forceFill(['failure_reason' => $reason])->save();
            }

            $locked->transitionWithHistory(
                $to,
                new StatusChange(actorId: $actorId, reason: $reason),
                ['source' => AccountWithdrawalChangeSource::Staff],
            );

            $this->audit->handle(new AuditEntry(
                action: 'account_withdrawal.'.$to->value,
                actorId: $actorId,
                auditableType: AccountWithdrawal::class,
                auditableId: $locked->id,
                after: ['status' => $to->value],
                reason: $reason,
                accountId: $locked->business_account_id,
                module: PermissionModule::Withdrawal->value,
            ));

            $locked->businessAccount->owner?->notify(new AccountWithdrawalStatusChanged($locked, $reason));

            return $locked;
        });
    }
}
