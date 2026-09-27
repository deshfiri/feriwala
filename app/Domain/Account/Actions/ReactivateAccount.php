<?php

namespace App\Domain\Account\Actions;

use App\Domain\Account\Data\AccountStatusChange;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Account\Models\BusinessAccountStatusChange;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Notifications\Account\AccountReactivated;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Lifts a suspension (§5.3).
 *
 * The missing half of {@see SuspendAccount}. §5.3 has always described
 * suspension as reversible and {@see AccountStatus::Suspended} has always
 * allowed the move back to {@see AccountStatus::Active} — but nothing
 * implemented it, so in practice a suspended business stayed suspended. A
 * reversibility nobody can exercise is not reversibility; it is closure under
 * a kinder name, which is exactly what §5.3 and D18 separate.
 *
 * A reason is required, on the same grounds suspension requires one: letting a
 * business trade again is a decision someone must own, and "why was this
 * lifted" is asked more often than "why was this imposed". The account
 * holder's note stays separate from the internal record (§7.3).
 *
 * **Reactivation is not activation.** It returns an account that had already
 * earned {@see AccountStatus::Active} to where it was, and grants nothing on
 * its own — no wallet is opened, no subscription started, no referral
 * commission calculated, because all of those happened the first time. An
 * account that never activated is not suspended-then-restored; it goes through
 * {@see ActivateAccount} like any other.
 */
class ReactivateAccount
{
    public function __construct(
        protected ChangeAccountStatus $changeStatus,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @param  string  $reason  the internal record. Required.
     * @param  string|null  $userVisibleNote  what the account holder is told, if anything
     *
     * @throws InvalidArgumentException when no reason is given, or the account is not suspended
     */
    public function handle(
        BusinessAccount $account,
        int $decidedBy,
        string $reason,
        ?string $userVisibleNote = null,
        ?string $internalNote = null,
    ): BusinessAccountStatusChange {
        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'Reactivating an account requires a recorded reason.'
            );
        }

        $change = $this->database->transaction(function () use ($account, $decidedBy, $reason, $userVisibleNote, $internalNote) {
            /** @var BusinessAccount $locked */
            $locked = BusinessAccount::query()->lockForUpdate()->findOrFail($account->id);

            // Checked under the lock rather than before it. Two reviewers
            // lifting the same suspension resolve to one winner, and the loser
            // is told what happened instead of writing a second history row
            // for a change that did not occur.
            if ($locked->status !== AccountStatus::Suspended) {
                throw new InvalidArgumentException(
                    'Only a suspended account can be reactivated; this one is '.$locked->status->label().'.'
                );
            }

            $from = $locked->status;

            $change = $this->changeStatus->handle($locked, new AccountStatusChange(
                to: AccountStatus::Active,
                changedBy: $decidedBy,
                reason: $reason,
                internalNote: $internalNote,
                userVisibleNote: $userVisibleNote,
            ));

            $this->audit->handle(new AuditEntry(
                action: 'account.reactivated',
                actorId: $decidedBy,
                auditableType: BusinessAccount::class,
                auditableId: $locked->id,
                before: ['status' => $from->value],
                after: ['status' => AccountStatus::Active->value],
                reason: $reason,
                note: $internalNote,
                accountId: $locked->id,
                module: 'account',
                // Restoring the ability to trade is as much an investigation's
                // business as removing it.
                isSensitive: true,
            ));

            $account->setRawAttributes($locked->getAttributes(), sync: true);

            return $change;
        });

        $account->owner?->notify(new AccountReactivated($userVisibleNote));

        return $change;
    }
}
