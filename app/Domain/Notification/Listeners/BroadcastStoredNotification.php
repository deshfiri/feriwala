<?php

namespace App\Domain\Notification\Listeners;

use App\Domain\Notification\Events\NotificationsChanged;
use App\Domain\Supplier\Models\Supplier;
use App\Models\User;
use Illuminate\Notifications\Events\NotificationSent;
use Throwable;

/**
 * Pushes every stored notification to its recipient's open tabs.
 *
 * Hooked on the framework's own `NotificationSent` for the `database` channel,
 * so each of the sixty-odd notification classes goes live without being edited,
 * and one added tomorrow does too.
 *
 * A socket server that is down must never cost the notification itself: the row
 * is already stored, and an exception here would fail the queued job and have
 * it retried — storing the same notification again. The failure is reported and
 * swallowed; the bell still catches up on the next page load.
 */
class BroadcastStoredNotification
{
    public function handle(NotificationSent $event): void
    {
        if ($event->channel !== 'database') {
            return;
        }

        $notifiable = $event->notifiable;

        if (! $notifiable instanceof User && ! $notifiable instanceof Supplier) {
            return;
        }

        try {
            event(NotificationsChanged::for($notifiable, NotificationsChanged::RECEIVED));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
