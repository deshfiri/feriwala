<?php

namespace App\Domain\Cms\Exceptions;

use RuntimeException;

class CmsMediaRefused extends RuntimeException
{
    public static function typeNotAccepted(string $mime): self
    {
        return new self("The file's real type ({$mime}) is not an accepted image format.");
    }

    public static function tooLarge(int $bytes, int $maxBytes): self
    {
        $mb = fn (int $value) => round($value / (1024 * 1024), 1);

        return new self("The file is {$mb($bytes)} MB, over the {$mb($maxBytes)} MB limit.");
    }

    public static function stillReferenced(string $filename, string $reason): self
    {
        return new self(
            "\"{$filename}\" cannot be deleted -- it is still used by {$reason}. ".
            'Remove that reference first, or archive the image instead of deleting it.'
        );
    }
}
