<?php

namespace App\Domain\Kyc\Batch;

use BadMethodCallException;
use Illuminate\Http\UploadedFile;

/**
 * A round that exists only to be looked at: the structure of a batch as it stands
 * now, with no evidence and nothing that can be written (D30).
 *
 * The builder's preview draws the applicant's own form from this, so what an
 * administrator previews is the form an applicant gets — same presenter, same
 * wording rules — without a submission, a file or an applicant behind it. Every
 * operation that would store something refuses.
 */
final class KycPreviewRound implements KycRound
{
    public function __construct(private readonly KycRoundDefinition $definition) {}

    public function definition(): KycRoundDefinition
    {
        return $this->definition;
    }

    public function audience(): string
    {
        return 'preview';
    }

    public function isEditable(): bool
    {
        return false;
    }

    public function hasBeenSubmitted(): bool
    {
        return false;
    }

    public function selections(): array
    {
        return [];
    }

    public function saveSelections(array $selections): void
    {
        throw new BadMethodCallException('A preview cannot be changed.');
    }

    public function evidence(): array
    {
        return [];
    }

    public function attachFile(string $requirement, string $slot, UploadedFile $file, bool $capturedLive = false, ?array $captureMeta = null): void
    {
        throw new BadMethodCallException('A preview cannot be changed.');
    }

    public function saveValue(string $requirement, string $slot, string $value): void
    {
        throw new BadMethodCallException('A preview cannot be changed.');
    }

    public function discard(string $requirement, string $slot): void
    {
        throw new BadMethodCallException('A preview cannot be changed.');
    }

    public function awaitsReview(): bool
    {
        return false;
    }

    public function recordVerdict(string $requirement, string $slot, string $status, ?string $note, int $reviewerId): void
    {
        throw new BadMethodCallException('A preview cannot be changed.');
    }
}
