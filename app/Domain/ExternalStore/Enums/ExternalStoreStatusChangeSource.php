<?php

namespace App\Domain\ExternalStore\Enums;

/**
 * What moved a connection's status (D29).
 */
enum ExternalStoreStatusChangeSource: string
{
    /** The partner, connecting, reconnecting or disconnecting. */
    case Account = 'account';

    /** A sync or a connection test reaching the store, or failing to. */
    case System = 'system';

    /** The scheduled sweep. */
    case Scheduler = 'scheduler';
}
