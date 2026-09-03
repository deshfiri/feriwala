<?php

namespace App\Domain\Account\Actions;

use App\Domain\Account\Data\AccountStatusChange;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\UserStatusChange;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Models\User;
use App\Notifications\Account\KycResubmissionRequested;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Sends an applicant back to correct their evidence (§7.3, §5.1).
 *
 * Not a rejection. The applicant is treated as legitimate and something in what
 * they supplied is not good enough — so the one thing this action will not do is
 * proceed without telling them what to fix. A correction request with no route
 * forward is a dead end wearing the clothes of a decision, and produces an
 * identical resubmission a week later.
 *
 * Distinct from {@see SuspendAccount} on purpose. Collapsing the two into one
 * "decline" loses the difference the applicant experiences, and hands both the
 * same permission when they should not have it.
 */
class RequestKycResubmission
{
    public function __construct(
        protected ChangeAccountStatus $changeStatus,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @param  string  $reason  kept internally, on the record of this decision
     * @param  string  $feedback  what the applicant is shown and must act on
     */
    public function handle(
        User $user,
        int $decidedBy,
        string $reason,
        string $feedback,
        ?string $internalNote = null,
    ): UserStatusChange {
        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'A correction request must record why, against the person who decided it.'
            );
        }

        if (trim($feedback) === '') {
            throw new InvalidArgumentException(
                'Asking for corrections without telling the applicant what to correct '
                .'guarantees they resubmit the same thing.'
            );
        }

        // Status change, history row and audit entry together. An audit trail
        // that can disagree with the account's own history is not a trail.
        $change = $this->database->transaction(function () use ($user, $decidedBy, $reason, $feedback, $internalNote) {
            /** @var User $locked */
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);

            $from = $locked->status;

            // Throws when the account has already moved on — the guard that
            // makes two reviewers deciding at once resolve to one winner.
            $change = $this->changeStatus->handle($locked, new AccountStatusChange(
                to: AccountStatus::KycResubmissionRequired,
                changedBy: $decidedBy,
                reason: $reason,
                internalNote: $internalNote,
                userVisibleNote: $feedback,
            ));

            // Leaving the approval queue. The stamp is cleared so a later
            // return does not look older than it is.
            $locked->forceFill(['approval_pending_at' => null])->save();

            $this->audit->handle(new AuditEntry(
                action: 'account.kyc_resubmission_requested',
                actorId: $decidedBy,
                auditableType: User::class,
                auditableId: $locked->id,
                before: ['status' => $from->value],
                after: ['status' => AccountStatus::KycResubmissionRequired->value],
                reason: $reason,
                note: $internalNote,
                accountId: $locked->id,
                module: 'account',
            ));

            $user->setRawAttributes($locked->getAttributes(), sync: true);

            return $change;
        });

        // After the transaction commits. Notifying inside it would tell someone
        // to fix their documents for a change that then rolled back.
        $user->notify(new KycResubmissionRequested($feedback));

        return $change;
    }
}
