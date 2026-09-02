<?php

namespace App\Domain\Kyc\Data;

use App\Domain\Kyc\Enums\KycStatus;
use InvalidArgumentException;

/**
 * A reviewer's decision on a KYC submission (§7.3).
 *
 * The three outcomes are named constructors rather than a status plus loose
 * arguments, so each one can demand what it actually needs — a rejection
 * without a reason is not a decision anyone can act on or defend later.
 */
class KycDecision
{
    private function __construct(
        public readonly KycStatus $outcome,
        public readonly ?int $reviewerId,
        public readonly ?string $reason,
        public readonly ?string $internalNote,
        public readonly ?string $userVisibleFeedback,
    ) {}

    public static function approve(
        int $reviewerId,
        ?string $internalNote = null,
    ): self {
        return new self(
            outcome: KycStatus::Approved,
            reviewerId: $reviewerId,
            reason: null,
            internalNote: $internalNote,
            userVisibleFeedback: null,
        );
    }

    /**
     * A final refusal.
     *
     * Both a reason and user-visible feedback are required: the applicant is
     * being turned away and is entitled to know why, and whoever handles the
     * complaint later needs the internal record.
     */
    public static function reject(
        int $reviewerId,
        string $reason,
        string $userVisibleFeedback,
        ?string $internalNote = null,
    ): self {
        if (trim($reason) === '' || trim($userVisibleFeedback) === '') {
            throw new InvalidArgumentException(
                'Rejecting KYC requires both a reason and feedback the applicant can see (§7.3).'
            );
        }

        return new self(
            outcome: KycStatus::Rejected,
            reviewerId: $reviewerId,
            reason: $reason,
            internalNote: $internalNote,
            userVisibleFeedback: $userVisibleFeedback,
        );
    }

    /**
     * Ask for corrections.
     *
     * Feedback is mandatory — "resubmission required" with no explanation sends
     * the applicant back to guess what was wrong, and they will usually submit
     * the same thing again.
     */
    public static function requestResubmission(
        int $reviewerId,
        string $userVisibleFeedback,
        ?string $reason = null,
        ?string $internalNote = null,
    ): self {
        if (trim($userVisibleFeedback) === '') {
            throw new InvalidArgumentException(
                'Requesting resubmission requires feedback telling the applicant what to fix (§7.3).'
            );
        }

        return new self(
            outcome: KycStatus::ResubmissionRequired,
            reviewerId: $reviewerId,
            reason: $reason,
            internalNote: $internalNote,
            userVisibleFeedback: $userVisibleFeedback,
        );
    }
}
