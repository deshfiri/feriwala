<?php

namespace App\Notifications\Supplier;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Shared shape for every Supplier-domain notification (D25).
 *
 * Mirrors `lang/en/notification.php`'s `events` convention already
 * established for the Client/Partner notifications: the database channel
 * stores only the event key plus its own fields, so a notification sent in
 * English reads in Bangla once the Supplier switches language, and the mail
 * channel pulls the same title/description rather than hardcoding English —
 * `->locale()` is set by the caller from the Supplier's own `locale` column,
 * since a queued mail job otherwise loses the request's locale entirely.
 *
 * Only a reviewer's own note is ever shown — never the internal reason a
 * decision was recorded with (§7.3-equivalent).
 */
abstract class SupplierLifecycleNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly ?string $note = null,
    ) {}

    /**
     * The `notification.events.*` key this notification reads its wording
     * from, e.g. `supplier.approved`.
     */
    abstract protected function eventKey(): string;

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject(self::line($this->eventKey(), 'title'))
            ->line(self::line($this->eventKey(), 'description'));

        if ($this->note !== null && trim($this->note) !== '') {
            $message->line($this->note);
        }

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => $this->eventKey(),
            'note' => $this->note,
            ...$this->extra(),
        ];
    }

    /**
     * The wording for one event, in the active locale.
     *
     * The whole `events` array is fetched and indexed rather than asking the
     * translator for `notification.events.supplier.approved.title`: event
     * names contain dots, so the translator would look for a nested
     * `supplier` array that does not exist and quietly return the key —
     * exactly what `RecentNotifications::line()` already guards against.
     */
    public static function line(string $event, string $part): string
    {
        $events = __('notification.events');

        $line = is_array($events) ? ($events[$event][$part] ?? null) : null;

        return is_string($line) ? $line : $event;
    }

    /**
     * Event-specific fields beyond `event` and `note`.
     *
     * @return array<string, mixed>
     */
    protected function extra(): array
    {
        return [];
    }
}
