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

    /**
     * Too little to create the order it is about (no store id, an amount or
     * the items missing). Kept with its payload; the order arrives complete
     * through the pull API, which a deferred event queues.
     */
    case Deferred = 'deferred';
}
