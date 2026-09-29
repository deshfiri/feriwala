<?php

namespace App\Domain\Withdrawal\Actions;

use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Supplier\Actions\AdvanceSupplierWithdrawalStatus;
use App\Domain\Withdrawal\Enums\AccountWithdrawalChangeSource;
use App\Domain\Withdrawal\Enums\AccountWithdrawalStatus;
use App\Domain\Withdrawal\Exceptions\AccountWithdrawalRefused;
use App\Domain\Withdrawal\Models\AccountWithdrawal;
use App\Support\StatusHistory\StatusChange;
use Illuminate\Database\DatabaseManager;

/**
 * Move a Client/Partner withdrawal forward without moving any money (§27,
 * mirrors {@see AdvanceSupplierWithdrawalStatus}):
 * Requested → Under review, Under review → Approved, Approved → Processing.
 *
 * No reservation changes here — money is set aside once, at request time,
 * and given back or paid exactly once, by
 * {@see RejectOrFailAccountWithdrawal} and {@see PayAccountWithdrawal}
 * respectively. This only records that a person looked at the request and
 * let it through.
 */
class AdvanceAccountWithdrawalStatus
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    public function handle(
        AccountWithdrawal $withdrawal,
        AccountWithdrawalStatus $to,
        int $actorId,
        ?string $note = null,
    ): AccountWithdrawal {
        return $this->database->transaction(function () use ($withdrawal, $to, $actorId, $note) {
            /** @var AccountWithdrawal $locked */
            $locked = AccountWithdrawal::query()->lockForUpdate()->findOrFail($withdrawal->id);

            if (! $locked->canTransitionTo($to)) {
                throw AccountWithdrawalRefused::illegalTransition($locked->reference, $locked->status->label());
            }

            $locked->transitionWithHistory(
                $to,
                new StatusChange(actorId: $actorId, publicNote: $note),
                ['source' => AccountWithdrawalChangeSource::Staff],
            );

            $this->audit->handle(new AuditEntry(
                action: 'account_withdrawal.'.$to->value,
                actorId: $actorId,
                auditableType: AccountWithdrawal::class,
                auditableId: $locked->id,
                after: ['status' => $to->value],
                accountId: $locked->business_account_id,
                module: PermissionModule::Withdrawal->value,
            ));

            return $locked;
        });
    }
}
