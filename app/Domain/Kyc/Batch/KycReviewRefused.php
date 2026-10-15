<?php

namespace App\Domain\Kyc\Batch;

use InvalidArgumentException;

/**
 * A review step the batch rules do not allow, refused before anything is written
 * (D30). The message is shown to the reviewer; for an approval it lists exactly
 * what still stands in the way.
 */
final class KycReviewRefused extends InvalidArgumentException
{
    /**
     * @param  array<int, string>  $problems
     */
    public function __construct(string $message, public readonly array $problems = [])
    {
        parent::__construct($message);
    }

    public static function legacyRound(): self
    {
        return new self(__('kyc.review.refused.legacy_round'));
    }

    public static function notAwaitingReview(): self
    {
        return new self(__('kyc.review.refused.not_awaiting_review'));
    }

    public static function nothingToJudge(): self
    {
        return new self(__('kyc.review.refused.nothing_to_judge'));
    }

    public static function noteRequired(): self
    {
        return new self(__('kyc.review.refused.note_required'));
    }

    public static function nothingRejected(): self
    {
        return new self(__('kyc.review.refused.nothing_rejected'));
    }

    /**
     * @param  array<int, string>  $problems
     */
    public static function notReadyToApprove(array $problems): self
    {
        return new self(__('kyc.review.refused.not_ready', ['items' => implode('; ', $problems)]), $problems);
    }
}
