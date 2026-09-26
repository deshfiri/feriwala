<?php

namespace App\Domain\Cms\Exceptions;

use RuntimeException;

class CmsRedirectRefused extends RuntimeException
{
    public static function wouldChain(string $fromPath, string $toPath): self
    {
        return new self(
            "Redirecting {$fromPath} to {$toPath} would chain into another redirect, or loop back on itself. ".
            'Point it at the final destination directly.'
        );
    }
}
