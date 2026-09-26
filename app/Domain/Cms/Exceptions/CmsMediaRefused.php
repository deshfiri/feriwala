<?php

namespace App\Domain\Cms\Exceptions;

use RuntimeException;

class CmsMediaRefused extends RuntimeException
{
    public static function typeNotAccepted(string $mime): self
    {
        return new self(sprintf(
            'A %s cannot be used as a CMS image. Accepted formats: JPEG, PNG and WebP.',
            $mime === '' ? 'file of that type' : $mime,
        ));
    }

    public static function tooLarge(int $bytes, int $maxBytes): self
    {
        return new self(sprintf(
            'That image is %d KB. CMS images go up to %d KB.',
            (int) round($bytes / 1024),
            (int) round($maxBytes / 1024),
        ));
    }

    public static function altTextRequired(): self
    {
        return new self('This image needs alt text in both English and Bangla before it can be placed on a published page.');
    }
}
