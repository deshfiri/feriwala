<?php

namespace App\Domain\Storage\Exceptions;

use App\Domain\Storage\Actions\StoreManagedFile;
use App\Domain\Website\Exceptions\WebsiteRefused;
use RuntimeException;

/**
 * A file {@see StoreManagedFile} refuses to
 * write -- its own type (from its bytes, never the browser's claim) is not
 * in the caller's allow-list, or it is larger than the caller's own cap.
 *
 * Generic on purpose: every caller already has its own domain-specific
 * refusal (e.g. {@see WebsiteRefused}) and
 * catches this to raise it, so this type never reaches a controller directly.
 */
class UnacceptableFile extends RuntimeException
{
    public const MIME_NOT_ACCEPTED = 'mime_not_accepted';

    public const TOO_LARGE = 'too_large';

    public function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function mimeNotAccepted(string $mime): self
    {
        return new self("A file of type [{$mime}] is not accepted.", self::MIME_NOT_ACCEPTED);
    }

    public static function tooLarge(int $maxBytes): self
    {
        return new self('The file is larger than '.number_format($maxBytes / 1024).' KB.', self::TOO_LARGE);
    }
}
