<?php

namespace App\Notifications\Requests;

use App\Notifications\Staff\WorkItemSubmitted;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Notification;

/**
 * Staff answered something the recipient asked for: a refund, a return, a
 * supplier availability update (§33.2).
 *
 * The event is `decision.{kind}.{outcome}`, so the wording and the destination
 * both come from lang and `RecentNotifications` the same way every other event's
 * do, and it reads in the recipient's own language.
 *
 * It says the outcome and names the request, nothing more. A reviewer's own
 * free-text note is deliberately left out: those notes were written for the
 * audit trail, and the requester's own screen shows whatever is meant for them.
 * `$links` carries only the public ids needed to build that screen's address.
 *
 * Database only, and idempotent on `$id` for the same reason as
 * {@see WorkItemSubmitted}.
 */
class RequestDecided extends Notification implements ShouldQueue
{
    use Queueable;

    public const REFUND = 'refund';

    public const RETURN = 'return';

    public const STOCK_UPDATE = 'stock_update';

    /**
     * @param  array<string, string>  $links  Public ids for the requester's own screen.
     */
    public function __construct(
        public readonly string $kind,
        public readonly bool $approved,
        public readonly ?string $reference = null,
        public readonly array $links = [],
    ) {}

    public function event(): string
    {
        return 'decision.'.$this->kind.'.'.($this->approved ? 'approved' : 'rejected');
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        return $this->id === null || ! DatabaseNotification::query()->whereKey($this->id)->exists();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => $this->event(),
            'reference' => $this->reference,
            ...$this->links,
        ];
    }
}
