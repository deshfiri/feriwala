<?php

namespace App\Domain\Kyc\Actions;

use App\Domain\Account\Actions\ChangeAccountStatus;
use App\Domain\Account\Actions\EvaluateActivationReadiness;
use App\Domain\Account\Data\AccountStatusChange;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Kyc\Data\KycDecision;
use App\Domain\Kyc\Enums\KycStatus;
use App\Domain\Kyc\Models\KycReview;
use App\Domain\Kyc\Models\KycSubmission;
use Illuminate\Database\DatabaseManager;

/**
 * Records a reviewer's decision on a KYC submission (§7.3).
 *
 * Four things move together, in one transaction: the submission's status, the
 * review record, the account's status, and the reviewed timestamp. A review row
 * without the status change — or a status change with no record of who made it —
 * would leave the account's history unable to explain itself, which is the whole
 * point of §7.3's field list.
 *
 * Approving KYC does **not** activate the account. §5.1 and §44 require payment
 * verification and administrative approval as well; this only clears one gate.
 */
class ReviewKyc
{
    public function __construct(
        protected ChangeAccountStatus $changeAccountStatus,
        protected EvaluateActivationReadiness $readiness,
        protected DatabaseManager $database,
    ) {}

    public function handle(KycSubmission $submission, KycDecision $decision): KycReview
    {
        $review = $this->database->transaction(function () use ($submission, $decision) {
            $from = $submission->status;

            // A submission that arrives straight from Submitted is picked up and
            // decided in one step; the state machine requires the intermediate
            // move, so make it explicitly rather than loosening the map.
            if ($from === KycStatus::Submitted && $decision->outcome !== KycStatus::ResubmissionRequired) {
                $submission->transitionTo(KycStatus::UnderReview);
                $from = KycStatus::UnderReview;
            }

            $submission->transitionTo($decision->outcome);
            $submission->reviewed_at = now();
            $submission->save();

            $review = KycReview::create([
                'kyc_submission_id' => $submission->id,
                'reviewer_id' => $decision->reviewerId,
                'from_status' => $from,
                'to_status' => $decision->outcome,
                'reason' => $decision->reason,
                'internal_note' => $decision->internalNote,
                'user_visible_feedback' => $decision->userVisibleFeedback,
            ]);

            $this->applyToAccount($submission, $decision);

            return $review;
        });

        // KYC approval or its withdrawal is one of the requirements the
        // activation gate watches (§5.1). Re-evaluated after the transaction
        // commits, so the gate never sees a half-applied decision.
        $account = $submission->businessAccount()->first();

        if ($account !== null) {
            $this->readiness->handle($account, 'KYC decision recorded.');
        }

        return $review;
    }

    /**
     * Move the account to match the review outcome.
     *
     * Only the applicant-facing feedback crosses over — the internal note stays
     * on the review, where the applicant cannot reach it.
     */
    protected function applyToAccount(KycSubmission $submission, KycDecision $decision): void
    {
        $account = $submission->businessAccount;

        if ($account === null) {
            return;
        }

        $target = match ($decision->outcome) {
            KycStatus::Approved => AccountStatus::KycApproved,
            KycStatus::Rejected => AccountStatus::KycRejected,
            KycStatus::ResubmissionRequired => AccountStatus::KycResubmissionRequired,
            default => null,
        };

        if ($target === null) {
            return;
        }

        // The account has to pass through review before a decision lands on it.
        if ($account->status === AccountStatus::KycSubmitted
            && $account->canTransitionTo(AccountStatus::KycUnderReview)) {
            $this->changeAccountStatus->handle($account, new AccountStatusChange(
                to: AccountStatus::KycUnderReview,
                changedBy: $decision->reviewerId,
                reason: 'Picked up for review.',
            ));
        }

        if (! $account->canTransitionTo($target)) {
            return;
        }

        $this->changeAccountStatus->handle($account, new AccountStatusChange(
            to: $target,
            changedBy: $decision->reviewerId,
            reason: $decision->reason,
            internalNote: $decision->internalNote,
            userVisibleNote: $decision->userVisibleFeedback,
        ));

        // Clearing KYC opens package selection (§5.1). It does not activate the
        // account — payment and administrative approval still stand between.
        if ($decision->outcome === KycStatus::Approved
            && $account->canTransitionTo(AccountStatus::PackageSelectionPending)) {
            $this->changeAccountStatus->handle($account, new AccountStatusChange(
                to: AccountStatus::PackageSelectionPending,
                reason: 'KYC approved — package selection open.',
            ));
        }
    }
}
