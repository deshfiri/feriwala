<?php

namespace App\Domain\Cases\Exceptions;

use RuntimeException;

/**
 * Evidence the ERP will not take, or take away (D35).
 *
 * The same shape as every other refusal: an HTTP status, a stable code, and a
 * message that never names a storage path, a disk or another Partner's data.
 */
class CaseEvidenceRefused extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public static function typeNotAccepted(): self
    {
        return new self(422, 'evidence_type_not_accepted', 'Attach a JPG, PNG, WebP or PDF file.');
    }

    public static function tooLarge(int $maxBytes): self
    {
        return new self(422, 'evidence_too_large', sprintf('Each file can be at most %d MB.', max(1, intdiv($maxBytes, 1024 * 1024))), [
            'max_bytes' => $maxBytes,
            'mb' => max(1, intdiv($maxBytes, 1024 * 1024)),
        ]);
    }

    public static function tooMany(int $max): self
    {
        return new self(422, 'evidence_too_many', sprintf('At most %d files can be attached.', $max), ['max' => $max]);
    }

    public static function notAllowedHere(): self
    {
        return new self(403, 'evidence_not_allowed', 'You may not add evidence here.');
    }

    public static function notYourDraft(): self
    {
        return new self(422, 'evidence_not_your_draft', 'That file is not one of your unsent attachments.');
    }

    public static function alreadySubmitted(): self
    {
        return new self(409, 'evidence_already_submitted', 'Evidence that was sent is kept and cannot be removed.');
    }

    public static function wrongPurpose(): self
    {
        return new self(422, 'evidence_wrong_purpose', 'That is not a kind of evidence you can add.');
    }
}
