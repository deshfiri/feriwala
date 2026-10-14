<?php

namespace App\Domain\Notification\Actions;

use App\Domain\Notification\Events\NotificationsChanged;
use App\Domain\Supplier\Models\Supplier;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Throwable;

/**
 * Marks a person's own notifications read, then tells their other tabs.
 *
 * Both entry points start from the notifiable's relationship, so a notification
 * id belonging to someone else resolves to nothing — there is no account or
 * user parameter to tamper with (§31.3).
 */
class MarkNotificationsRead
{
    /**
     * One notification. Returns false when it is not theirs or does not exist.
     */
    public function one(User|Supplier $notifiable, string $notificationId): bool
    {
        /** @var DatabaseNotification|null $notification */
        $notification = $notifiable->notifications()->whereKey($notificationId)->first();

        if ($notification === null) {
            return false;
        }

        $notification->markAsRead();

        $this->announce($notifiable);

        return true;
    }

    /**
     * Everything still unread. Returns how many rows changed.
     */
    public function all(User|Supplier $notifiable): int
    {
        $changed = $notifiable->unreadNotifications()->update(['read_at' => now()]);

        if ($changed > 0) {
            $this->announce($notifiable);
        }

        return $changed;
    }

    /**
     * A socket failure must not undo a read the person just made.
     */
    protected function announce(User|Supplier $notifiable): void
    {
        try {
            event(NotificationsChanged::for($notifiable, NotificationsChanged::READ));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
