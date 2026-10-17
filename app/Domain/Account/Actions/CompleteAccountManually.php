<?php

namespace App\Domain\Account\Actions;

use App\Domain\Account\ActivationRequirements;
use App\Domain\Account\Data\AccountStatusChange;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Exceptions\ActivationBlocked;
use App\Domain\Account\MobileVerificationRequirement;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Kyc\Actions\ReviewKyc;
use App\Domain\Kyc\Batch\KycReviewRefused;
use App\Domain\Kyc\Data\KycDecision;
use App\Domain\Kyc\Enums\KycRoundPurpose;
use App\Domain\Kyc\Enums\KycStatus;
use App\Domain\Kyc\Models\KycSubmission;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * An administrator completes an account's onboarding by hand and activates it.
 *
 * For the applicant who is verified, documented and settled somewhere the
 * platform cannot see (paid in person, vouched for, onboarded on a call), and
 * who should not have to repeat the funnel. It does not skip the three §5.1
 * conditions; it satisfies each one through a recorded decision, so the
 * account's history and audit trail still explain how it got to Active:
 *
 * - **Email and mobile** are confirmed through {@see VerifyContactManually}, which
 *   keeps its own reason, actor and "staff verified" marker.
 * - **KYC** is approved through {@see ReviewKyc} on a real round, so the review
 *   row, the status moves and the "reviewed by" are all there. An open round is
 *   reviewed; otherwise a manual round is opened for the purpose.
 * - **The activation payment is waived**, not faked. No payment row and no
 *   ledger entry is written, because no money moved; the account records who
 *   waived it, when and why, and {@see ActivationRequirements} reads that.
 *
 * Then {@see ActivateAccount} runs as it always does, so the wallet, the
 * deposit obligation, the referral trigger and any pending subscription happen
 * exactly as they would for any other activation. Each step is itself atomic
 * and the whole is safe to run again: a failure part-way leaves the steps that
 * finished in place, and running it again finishes the rest.
 */
class CompleteAccountManually
{
    /**
     * The next step on the §5.1 funnel from each status that can still be
     * walked. A status absent from this map is not one this action moves.
     *
     * @var array<string, AccountStatus>
     */
    protected const NEXT = [
        'registered' => AccountStatus::KycPending,
        'mobile_verification_pending' => AccountStatus::KycPending,
        'email_verification_pending' => AccountStatus::KycPending,
        'kyc_pending' => AccountStatus::KycSubmitted,
        'kyc_submitted' => AccountStatus::KycUnderReview,
        'kyc_under_review' => AccountStatus::KycApproved,
        'kyc_rejected' => AccountStatus::KycResubmissionRequired,
        'kyc_resubmission_required' => AccountStatus::KycSubmitted,
        'kyc_approved' => AccountStatus::PackageSelectionPending,
        'package_selection_pending' => AccountStatus::PaymentPending,
        'payment_pending' => AccountStatus::PaymentVerificationPending,
    ];

    public function __construct(
        protected ActivationRequirements $requirements,
        protected MobileVerificationRequirement $mobileVerification,
        protected VerifyContactManually $verifyContact,
        protected ReviewKyc $reviewKyc,
        protected ChangeAccountStatus $changeStatus,
        protected ActivateAccount $activate,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @throws InvalidArgumentException when the account cannot be completed, or no reason is given
     * @throws ActivationBlocked
     */
    public function handle(BusinessAccount $account, User $actor, string $reason): BusinessAccount
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('A reason is required and is recorded against this decision.');
        }

        $this->guardEligible($account);

        $from = $account->status;
        $owner = $account->owner;

        if ($owner === null) {
            throw new InvalidArgumentException('This account has no owner to complete.');
        }

        $this->confirmContacts($actor, $owner, $reason);

        $this->approveKyc($account->refresh(), $actor, $reason);

        $account->refresh();

        if (! $account->isActivated()) {
            $this->waivePaymentAndAdvance($account, $actor, $reason);

            $this->activate->handle($account->refresh(), $actor->id, $reason);
        }

        $this->audit->handle(new AuditEntry(
            action: 'account.completed_manually',
            actorId: $actor->id,
            auditableType: BusinessAccount::class,
            auditableId: $account->id,
            before: ['status' => $from->value],
            after: [
                'status' => $account->status->value,
                'activation_payment_waived' => $account->activationPaymentIsWaived(),
            ],
            reason: $reason,
            accountId: $account->id,
            module: 'account',
            isSensitive: true,
        ));

        return $account;
    }

    /**
     * Only an account still on the onboarding funnel. An active, suspended or
     * closed account has its own actions, and walking one back onto the funnel
     * would be a different decision from the one being made here.
     *
     * @throws InvalidArgumentException
     */
    protected function guardEligible(BusinessAccount $account): void
    {
        $status = $account->status;

        if ($status === AccountStatus::ApprovalPending || array_key_exists($status->value, self::NEXT)) {
            return;
        }

        throw new InvalidArgumentException(
            $status->isActivated()
                ? 'This account is already active.'
                : 'This account is '.$status->label().' and cannot be completed manually.',
        );
    }

    /**
     * Confirm what is not yet confirmed, and nothing that already is.
     *
     * Mobile only when activation currently requires it: confirming a number
     * nobody is asking for would leave a staff-verified marker with no purpose.
     *
     * @throws InvalidArgumentException
     */
    protected function confirmContacts(User $actor, User $owner, string $reason): void
    {
        if ($owner->email_verified_at === null) {
            $this->verifyContact->handle($actor, $owner, VerifyContactManually::EMAIL, $reason);
            $owner->refresh();
        }

        if ($this->mobileVerification->isRequired() && $owner->mobile_verified_at === null) {
            $this->verifyContact->handle($actor, $owner, VerifyContactManually::MOBILE, $reason);
            $owner->refresh();
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    protected function approveKyc(BusinessAccount $account, User $actor, string $reason): void
    {
        if ($this->requirements->kycApproved($account)) {
            return;
        }

        // The account has to be at "submitted" for a review to land on it. Only
        // walked from the statuses before that point: from anywhere later the
        // walk would carry on past it.
        if (in_array($account->status, [
            AccountStatus::Registered,
            AccountStatus::MobileVerificationPending,
            AccountStatus::EmailVerificationPending,
            AccountStatus::KycPending,
            AccountStatus::KycRejected,
            AccountStatus::KycResubmissionRequired,
        ], true)) {
            $this->walk($account, $actor, AccountStatus::KycSubmitted, 'KYC completed by an administrator.');
        }

        $submission = $this->openRound($account);

        try {
            $this->reviewKyc->handle($submission, KycDecision::approve($actor->id, 'Completed manually: '.$reason));
        } catch (KycReviewRefused $refused) {
            // A batch round whose evidence is not all accepted. Approving it
            // over the top would put "approved" on documents nobody accepted.
            throw new InvalidArgumentException(
                'This KYC round has evidence that has not been accepted. Review it from the KYC screen first. '.$refused->getMessage(),
            );
        }
    }

    /**
     * The round to approve: the one already waiting for a decision, or a new
     * one opened for the purpose. A new round is never a batch round, because
     * a batch round is approved on its evidence and this one has none.
     */
    protected function openRound(BusinessAccount $account): KycSubmission
    {
        $latest = KycSubmission::query()
            ->where('business_account_id', $account->id)
            ->orderByDesc('round')
            ->lockForUpdate()
            ->first();

        if ($latest !== null && $latest->status->awaitsReview()) {
            return $latest;
        }

        // A flat draft already holding documents (the applicant's, or staff
        // uploaded on their behalf) is the evidence being approved, so it is
        // the round that is sent, not a second empty one beside it.
        if ($latest !== null && $latest->status === KycStatus::Draft && $latest->kyc_batch_version_id === null) {
            $latest->transitionTo(KycStatus::Submitted);
            $latest->forceFill(['submitted_at' => now()])->save();

            return $latest;
        }

        $submission = KycSubmission::create([
            'business_account_id' => $account->id,
            'status' => KycStatus::Draft,
            'round' => ($latest->round ?? 0) + 1,
            'purpose' => KycRoundPurpose::Onboarding,
        ]);

        $submission->transitionTo(KycStatus::Submitted);
        $submission->forceFill(['submitted_at' => now()])->save();

        return $submission;
    }

    /**
     * Record the waiver and bring the account to the payment-verified step,
     * under one row lock so the two cannot disagree.
     */
    protected function waivePaymentAndAdvance(BusinessAccount $account, User $actor, string $reason): void
    {
        $this->database->transaction(function () use ($account, $actor, $reason) {
            /** @var BusinessAccount $locked */
            $locked = BusinessAccount::query()->lockForUpdate()->findOrFail($account->id);

            if (! $locked->activationPaymentIsWaived() && ! $this->requirements->activationPaid($locked)) {
                $locked->forceFill([
                    'activation_payment_waived_at' => now(),
                    'activation_payment_waived_by' => $actor->id,
                    'activation_payment_waiver_reason' => $reason,
                ])->save();
            }

            $this->walk($locked, $actor, AccountStatus::PaymentVerificationPending, 'Activation payment waived by an administrator.');
        });
    }

    /**
     * Walk the account forward along the funnel until it reaches `$target`, or
     * as far as the state machine allows. Each step is a real, recorded
     * transition; nothing assigns the status.
     */
    protected function walk(BusinessAccount $account, User $actor, AccountStatus $target, string $reason): void
    {
        $guard = 0;

        while ($account->status !== $target && $guard++ < 12) {
            $next = self::NEXT[$account->status->value] ?? null;

            if ($next === null || ! $account->canTransitionTo($next)) {
                return;
            }

            $this->changeStatus->handle($account, new AccountStatusChange(
                to: $next,
                changedBy: $actor->id,
                reason: $reason,
            ));
        }
    }
}
