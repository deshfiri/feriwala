<?php

namespace App\Domain\Kyc\Enums;

use App\Domain\Account\Enums\AccountStatus;
use App\Support\StateMachine\TransitionableState;

/**
 * The lifecycle of one KYC submission (§7.3).
 *
 * Separate from {@see AccountStatus}: the account has
 * its own funnel, and a submission is one round within it. Keeping them apart
 * means a resubmission can be tracked without inventing account statuses for
 * every review outcome.
 */
enum KycStatus: string implements TransitionableState
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case ResubmissionRequired = 'resubmission_required';
    case Approved = 'approved';
    case Rejected = 'rejected';

    /**
     * @return array<int, self>
     */
    public function transitionsTo(): array
    {
        return match ($this) {
            self::Draft => [self::Submitted],

            self::Submitted => [self::UnderReview, self::ResubmissionRequired],

            // The three reviewer outcomes of §7.3.
            self::UnderReview => [
                self::Approved,
                self::Rejected,
                self::ResubmissionRequired,
            ],

            // A round that needs corrections is closed; the applicant starts a
            // new round rather than editing a reviewed one, so the reviewer can
            // see what changed.
            self::ResubmissionRequired => [],

            // Approval can be withdrawn if something later comes to light —
            // §7.2 allows requesting a future KYC update.
            self::Approved => [self::ResubmissionRequired],

            self::Rejected => [self::ResubmissionRequired],
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::ResubmissionRequired;
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Submitted',
            self::UnderReview => 'Under review',
            self::ResubmissionRequired => 'Resubmission required',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Approved => 'success',
            self::Rejected => 'danger',
            self::ResubmissionRequired => 'warning',
            self::Submitted, self::UnderReview => 'info',
            self::Draft => 'neutral',
        };
    }

    /**
     * Whether a reviewer still has to act on this round.
     */
    public function awaitsReview(): bool
    {
        return in_array($this, [self::Submitted, self::UnderReview], true);
    }

    /**
     * Whether the applicant can still change this round.
     *
     * Only a draft. Once submitted, the contents are what the reviewer saw, and
     * letting them change underneath would make the review meaningless.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }
}
