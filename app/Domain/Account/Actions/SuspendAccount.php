<?php

namespace App\Domain\Account\Actions;

use App\Domain\Account\Data\AccountStatusChange;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\UserStatusChange;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Models\User;
use App\Notifications\Account\AccountSuspended;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Suspends an account (§5.3).
 *
 * **Not** a permanent denial, and must not be used as one. §5.3 keeps
 * {@see AccountStatus::Suspended} reversible — an administrator can lift it —
 * whereas closure is terminal and belongs to the closure and retention workflow
 * (D18) with its own retention rules. Treating suspension as a quiet substitute
 * for closure would put accounts beyond reach with none of the guarantees that
 * closure carries.
 *
 * An internal reason is required and is not optional wording: suspension removes
 * someone's ability to trade, and the record of why has to survive the person
 * who decided it. The applicant-facing note is deliberately separate, so a
 * private assessment cannot leak into what the account holder reads (§7.3).
 */
class SuspendAccount
{
    public function __construct(
        protected ChangeAccountStatus $changeStatus,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @param  string  $reason  the internal record. Required.
     * @param  string|null  $userVisibleNote  what the account holder is told, if anything
     */
    public function handle(
        User $user,
        int $decidedBy,
        string $reason,
        ?string $userVisibleNote = null,
        ?string $internalNote = null,
    ): UserStatusChange {
        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'Suspending an account requires a recorded reason.'
            );
        }

        $change = $this->database->transaction(function () use ($user, $decidedBy, $reason, $userVisibleNote, $internalNote) {
            /** @var User $locked */
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);

            $from = $locked->status;

            // Throws when the account has already moved on — the guard that
            // makes two reviewers deciding at once resolve to one winner.
            $change = $this->changeStatus->handle($locked, new AccountStatusChange(
                to: AccountStatus::Suspended,
                changedBy: $decidedBy,
                reason: $reason,
                internalNote: $internalNote,
                userVisibleNote: $userVisibleNote,
            ));

            $locked->forceFill(['approval_pending_at' => null])->save();

            $this->audit->handle(new AuditEntry(
                action: 'account.suspended',
                actorId: $decidedBy,
                auditableType: User::class,
                auditableId: $locked->id,
                before: ['status' => $from->value],
                after: ['status' => AccountStatus::Suspended->value],
                reason: $reason,
                note: $internalNote,
                accountId: $locked->id,
                module: 'account',
                // Suspension is a decision an investigation will want to find.
                isSensitive: true,
            ));

            $user->setRawAttributes($locked->getAttributes(), sync: true);

            return $change;
        });

        $user->notify(new AccountSuspended($userVisibleNote));

        return $change;
    }
}
