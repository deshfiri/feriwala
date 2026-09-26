<?php

namespace App\Domain\Cms\Exceptions;

use App\Domain\Cms\Models\Page;
use RuntimeException;

class CmsSchedulingRefused extends RuntimeException
{
    public static function notCurrentlyScheduled(Page $page): self
    {
        return new self(
            "\"{$page->slug}\" has no pending scheduled publish to cancel -- ".
            "it is currently {$page->publication_state->value}."
        );
    }
}
