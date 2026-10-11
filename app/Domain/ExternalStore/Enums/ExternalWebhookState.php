<?php

namespace App\Domain\ExternalStore\Enums;

/**
 * Where one received store notification stands (D29).
 */
enum ExternalWebhookState: string
{
    case Received = 'received';
    case Processed = 'processed';

    /** Understood, and nothing to do: an event this integration does not use. */
    case Ignored = 'ignored';

    case Failed = 'failed';
}
