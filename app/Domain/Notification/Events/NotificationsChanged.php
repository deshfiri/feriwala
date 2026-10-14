<?php

namespace App\Domain\Notification\Events;

use App\Domain\Supplier\Models\Supplier;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Tells one person's open tabs that their notifications changed (§33.2).
 *
 * It carries no notification text on purpose. Wording is rendered in the
 * recipient's own locale by their own authenticated request, and a socket
 * payload is a place where a title could outlive a permission change. The
 * browser treats this as a nudge and refetches what it is allowed to see; the
 * `unread` figure is only there so the badge can move before that arrives.
 *
 * Sent straight away rather than queued: it is dispatched from inside an
 * already-queued job, and a second hop would only add latency.
 */
class NotificationsChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public const RECEIVED = 'received';

    public const READ = 'read';

    public function __construct(
        public readonly string $channelName,
        public readonly string $reason,
        public readonly int $unread,
    ) {}

    /**
     * Private channel name for a recipient. Public ids, never row ids (§ no
     * database IDs in public addresses) — the name is visible in the browser.
     */
    public static function channelFor(User|Supplier $notifiable): string
    {
        return ($notifiable instanceof Supplier ? 'notifications.supplier.' : 'notifications.user.').$notifiable->public_id;
    }

    public static function for(User|Supplier $notifiable, string $reason): self
    {
        return new self(
            self::channelFor($notifiable),
            $reason,
            $notifiable->unreadNotifications()->count(),
        );
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel($this->channelName)];
    }

    public function broadcastAs(): string
    {
        return 'notifications.changed';
    }

    /**
     * @return array{reason: string, unread: int}
     */
    public function broadcastWith(): array
    {
        return ['reason' => $this->reason, 'unread' => $this->unread];
    }
}
