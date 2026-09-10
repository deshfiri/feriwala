<?php

namespace App\Domain\Notification\Enums;

/**
 * Where one text message got to (§30.2).
 *
 * Four states, and the fourth is the one worth having. **Suppressed** is a
 * message the platform decided not to send — SMS switched off globally, or off
 * for this event — and it is not a failure. Recording it as one would fill the
 * failed-SMS log with messages nobody wanted sent, and hide the ones that
 * actually broke.
 */
enum SmsStatus: string
{
    /** Written down and handed to the queue. */
    case Queued = 'queued';

    /** The provider accepted it. */
    case Sent = 'sent';

    /** The provider refused it, or kept failing. */
    case Failed = 'failed';

    /** Deliberately not sent. A setting said so. */
    case Suppressed = 'suppressed';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Sent => 'Sent',
            self::Failed => 'Failed',
            self::Suppressed => 'Not sent',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Sent => 'success',
            self::Failed => 'danger',
            self::Queued => 'info',
            self::Suppressed => 'neutral',
        };
    }
}
