<?php

namespace App\Domain\Kyc\Actions;

use App\Domain\Account\Actions\ChangeAccountStatus;
use App\Domain\Account\Data\AccountStatusChange;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Kyc\Enums\KycStatus;
use App\Domain\Kyc\Models\KycDeadlineEvent;
use App\Domain\Kyc\Models\KycSubmission;
use App\Notifications\Kyc\KycDeadlineRestrictionLifted;
use Illuminate\Database\DatabaseManager;

/**
 * Gives an account back what a missed KYC deadline took (§7.4).
 *
 * A restriction has to be reversible by the applicant's own effort. Without
 * this, someone who missed a deadline, then sent exactly what was asked and had
 * it approved, would stay restricted until a human noticed — which is a trap
 * dressed as a policy, and turns a deadline into a punishment nobody intended.
 *
 * Only lifts what the deadline imposed. Three conditions, all necessary:
 *
 *   - the account is in the state the deadline put it in, not one an
 *     administrator chose afterwards — a business suspended for fraud must not
 *     be released by approving a document
 *   - a recorded `enforced` event says this restriction was ours to lift
 *   - KYC is now approved, so the reason for the restriction is actually gone
 *
 * Idempotent through {@see KycDeadlineEvent::claim()}, like enforcement: the
 * `restored` claim is an insert against a unique index, so a second approval or
 * a retried job cannot produce a second audit entry or a second notification.
 */
class LiftKycDeadlineRestriction
{
    public function __construct(
        protected ChangeAccountStatus $changeStatus,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @return bool whether a restriction was lifted
     */
    public function handle(BusinessAccount $account): bool
    {
        $lifted = $this->database->transaction(function () use ($account) {
            /** @var BusinessAccount|null $locked */
            $locked = BusinessAccount::query()->lockForUpdate()->find($account->id);

            if ($locked === null || $locked->status !== AccountStatus::TemporarilyRestricted) {
                return null;
            }

            $enforced = KycDeadlineEvent::query()
                ->where('business_account_id', $locked->id)
                ->where('event', KycDeadlineEvent::ENFORCED)
                ->where('account_restricted', true)
                ->latest('id')
                ->first();

            // No recorded deadline restriction: this account is restricted for
            // some other reason, and that reason is not ours to overrule.
            if ($enforced === null) {
                return null;
            }

            if (! $this->kycNowApproved($locked)) {
                return null;
            }

            $submission = KycSubmission::query()->find($enforced->kyc_submission_id);

            if ($submission === null
                || KycDeadlineEvent::claim($submission, KycDeadlineEvent::RESTORED) === null) {
                return null;
            }

            $this->changeStatus->handle($locked, AccountStatusChange::automatic(
                AccountStatus::Active,
                'KYC completed — the deadline restriction has been lifted.',
            ));

            $this->audit->handle(new AuditEntry(
                action: 'kyc.deadline_restriction_lifted',
                actorType: 'system',
                auditableType: BusinessAccount::class,
                auditableId: $locked->id,
                before: ['status' => AccountStatus::TemporarilyRestricted->value],
                after: ['status' => AccountStatus::Active->value],
                reason: 'KYC approved after a missed deadline (§7.4).',
                accountId: $locked->id,
                module: 'kyc',
            ));

            $account->setRawAttributes($locked->getAttributes(), sync: true);

            return true;
        });

        if ($lifted === null) {
            return false;
        }

        $account->owner?->notify(new KycDeadlineRestrictionLifted);

        return true;
    }

    /**
     * Whether the reason for the restriction has actually gone.
     */
    protected function kycNowApproved(BusinessAccount $account): bool
    {
        return KycSubmission::query()
            ->where('business_account_id', $account->id)
            ->where('status', KycStatus::Approved)
            ->exists();
    }
}
