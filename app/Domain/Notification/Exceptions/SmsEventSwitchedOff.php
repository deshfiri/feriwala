<?php

namespace App\Domain\Notification\Exceptions;

use App\Domain\Notification\Enums\SmsEvent;
use RuntimeException;

/**
 * A one-time code was asked for while an administrator has its SMS switched
 * off under Admin → SMS.
 *
 * Raised rather than swallowed, so the screen can say that no code is coming
 * instead of claiming one was sent.
 */
class SmsEventSwitchedOff extends RuntimeException
{
    public function __construct(public readonly SmsEvent $event)
    {
        parent::__construct("SMS for [{$event->value}] is switched off.");
    }
}
