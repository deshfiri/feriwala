<?php

namespace App\Domain\ExternalStore\Enums;

/**
 * Who an external order's history entry came from (D29).
 */
enum ExternalOrderEventSource: string
{
    /** The partner's store, through a sync or a signed webhook. */
    case ExternalStore = 'external_store';

    case System = 'system';
}
