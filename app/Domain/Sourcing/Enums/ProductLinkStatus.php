<?php

namespace App\Domain\Sourcing\Enums;

/**
 * Whether a Same Product link currently counts. An unlinked row stays as the
 * record of what staff had confirmed, and when.
 */
enum ProductLinkStatus: string
{
    case Active = 'active';
    case Unlinked = 'unlinked';
}
