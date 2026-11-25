<?php

namespace App\Domain\Account\Actions;

use App\Domain\Account\Data\AccountStatusChange;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Account\OnboardingApplication;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Notifications\Account\ApplicationCorrectionRequested;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * A reviewer sends a submitted application back for correction (D39 step 8):
 * the personal details, the business, the preferred domain, or several.
 *
 * Not a rejection, and not KYC — identity documents keep their own
 * {@see RequestKycResubmission}. The submission is taken back, each named
 * section is reopened, the partner is told exactly what to fix, and the account
 * leaves the approval gate until they resubmit. Like every review decision it
 * needs a reason on record and feedback the partner can act on.
 */
class RequestApplicationCorrection
{
    public function __construct(
        protected ChangeAccountStatus $changeStatus,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @param  list<string>  $sections  any of personal, business, domain
     * @param  string  $reason  kept internally, on the record of this decision
     * @param  string  $feedback  what the partner is shown and must act on
     */
    public function handle(
        BusinessAccount $account,
        int $decidedBy,
        array $sections,
        string $reason,
        string $feedback,
        ?string $internalNote = null,
    ): BusinessAccount {
        $sections = array_values(array_unique(array_intersect($sections, OnboardingApplication::SECTIONS)));

        if ($sections === []) {
            throw new InvalidArgumentException('Name at least one part of the application to correct.');
        }

        if (trim($reason) === '' || trim($feedback) === '') {
            throw new InvalidArgumentException('A correction request needs a reason on record and feedback the partner can act on.');
        }

        $this->database->transaction(function () use ($account, $decidedBy, $sections, $reason, $feedback, $internalNote): void {
            /** @var BusinessAccount $locked */
            $locked = BusinessAccount::query()->lockForUpdate()->findOrFail($account->id);

            if (! $locked->status->isOnboarding()) {
                throw new InvalidArgumentException('Only an application still in onboarding can be sent back for correction.');
            }

            $from = $locked->status;

            // Back from the approval gate to the application. Before the gate
            // (KYC still in review), the status stays where it is.
            if ($from === AccountStatus::ApprovalPending) {
                $this->changeStatus->handle($locked, new AccountStatusChange(
                    to: AccountStatus::KycApproved,
                    changedBy: $decidedBy,
                    reason: $reason,
                    internalNote: $internalNote,
                    userVisibleNote: $feedback,
                ));
            }

            $locked->forceFill([
                'application_submitted_at' => null,
                'approval_pending_at' => null,
                'correction_sections' => $sections,
                'correction_feedback' => trim($feedback),
                'personal_details_completed_at' => in_array('personal', $sections, true) ? null : $locked->personal_details_completed_at,
                'business_setup_completed_at' => array_intersect(['business', 'domain'], $sections) !== [] ? null : $locked->business_setup_completed_at,
            ])->save();

            $this->audit->handle(new AuditEntry(
                action: 'account.application_correction_requested',
                actorId: $decidedBy,
                auditableType: BusinessAccount::class,
                auditableId: $locked->id,
                before: ['status' => $from->value, 'submitted' => true],
                after: ['status' => $locked->status->value, 'submitted' => false, 'sections' => $sections],
                reason: $reason,
                note: $internalNote,
                accountId: $locked->id,
                module: 'account',
            ));

            $account->setRawAttributes($locked->getAttributes(), sync: true);
        });

        // After the commit: never tell someone to fix something that rolled back.
        $account->owner?->notify(new ApplicationCorrectionRequested($sections, trim($feedback)));

        return $account;
    }
}
