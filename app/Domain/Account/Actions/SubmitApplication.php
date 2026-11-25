<?php

namespace App\Domain\Account\Actions;

use App\Domain\Account\Exceptions\OnboardingRefused;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Account\OnboardingApplication;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Models\User;
use Illuminate\Database\DatabaseManager;

/**
 * Onboarding step 7 (D39): the partner submits their application for
 * verification.
 *
 * Only once everything is in: the package paid for, the identity documents
 * submitted, personal details and business setup complete. The submission is
 * recorded, any correction a reviewer asked for is cleared, and the account
 * goes to the approval gate as soon as its KYC is approved — where a person
 * approves it (D39). Submitting twice changes nothing.
 */
class SubmitApplication
{
    public function __construct(
        protected OnboardingApplication $application,
        protected AdvanceOnboarding $onboarding,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @throws OnboardingRefused
     */
    public function handle(BusinessAccount $account, User $actor): BusinessAccount
    {
        $submitted = $this->database->transaction(function () use ($account, $actor): ?BusinessAccount {
            /** @var BusinessAccount $locked */
            $locked = BusinessAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();

            if ($locked->hasSubmittedApplication()) {
                return null;
            }

            $this->application->assertEditable($locked);

            if (! $this->application->kycSubmitted($locked)) {
                throw OnboardingRefused::because('kyc_first');
            }

            if ($locked->personal_details_completed_at === null) {
                throw OnboardingRefused::because('personal_first');
            }

            if ($locked->business_setup_completed_at === null || $locked->preferredDomain() === null) {
                throw OnboardingRefused::because('business_first');
            }

            $resubmission = ($locked->correction_sections ?? []) !== [] || $locked->correction_feedback !== null;

            $locked->forceFill([
                'application_submitted_at' => now(),
                'correction_sections' => null,
                'correction_feedback' => null,
            ])->save();

            $this->audit->handle(new AuditEntry(
                action: $resubmission ? 'onboarding.application_resubmitted' : 'onboarding.application_submitted',
                actorId: $actor->id,
                auditableType: BusinessAccount::class,
                auditableId: $locked->id,
                after: [
                    'status' => $locked->status->value,
                    'preferred_domain' => $locked->preferredDomain(),
                ],
                accountId: $locked->id,
                module: 'account',
            ));

            return $locked;
        });

        if ($submitted === null) {
            return $account->refresh();
        }

        // To the approval gate once KYC is approved too; never activated here.
        $this->onboarding->handle($submitted, 'Application submitted.');

        return $submitted->refresh();
    }
}
