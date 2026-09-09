<?php

namespace App\Domain\Notification\Queries;

use App\Domain\Notification\Data\HeaderNotification;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The notifications behind the header bell (§33.2, D20).
 *
 * Scoped to the signed-in user by the relationship itself, which is what keeps
 * §31.3 honest here — there is no account or user parameter to tamper with,
 * because the only way in is through the person Laravel already authenticated.
 *
 * Capped deliberately. The bell is a summary, not an archive: a person with
 * eight hundred notifications should not pay for eight hundred rows on every
 * page load to render a list that shows ten.
 */
class RecentNotifications
{
    /**
     * How many rows the menu shows. Beyond this the answer is a full page, not
     * a longer dropdown.
     */
    public const LIMIT = 10;

    /**
     * The free-text fields an event may carry, in the order they are preferred.
     *
     * An administrator's own words say what to actually do about the
     * notification; the generic line only says what happened.
     *
     * @var array<int, string>
     */
    protected const DETAIL_KEYS = ['instructions', 'feedback', 'note'];

    /**
     * @return array<int, HeaderNotification>
     */
    public function forUser(User $user): array
    {
        return $user->notifications()
            /*
             * A stable tie-break on top of the relation's `created_at desc`.
             * Two notifications written in the same transaction share a
             * timestamp to the second, and without this their order is whatever
             * the database happens to return — so the bell reshuffles between
             * page loads and the tenth row can be shown twice or not at all.
             */
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (DatabaseNotification $notification) => $this->present($notification))
            ->all();
    }

    /**
     * How many of this person's notifications are still unread.
     *
     * Counted rather than derived from the capped list above: someone with
     * twelve unread should not be told they have ten.
     */
    public function unreadCountFor(User $user): int
    {
        return $user->unreadNotifications()->count();
    }

    protected function present(DatabaseNotification $notification): HeaderNotification
    {
        /** @var array<string, mixed> $data */
        $data = $notification->data;
        $event = is_string($data['event'] ?? null) ? $data['event'] : 'unknown';

        return new HeaderNotification(
            id: $notification->id,
            title: $this->line($event, 'title') ?? $event,
            description: $this->description($event, $data),
            href: $this->href($event),
            createdAt: $notification->created_at?->diffForHumans(),
            createdAtIso: $notification->created_at?->toIso8601String(),
            readAt: $notification->read_at?->toIso8601String(),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function description(string $event, array $data): ?string
    {
        foreach (self::DETAIL_KEYS as $key) {
            if (filled($data[$key] ?? null) && is_string($data[$key])) {
                return $data[$key];
            }
        }

        return $this->line($event, 'description');
    }

    /**
     * A translated line for this event, or null when there is none.
     *
     * The whole `events` array is fetched and indexed here rather than asking
     * the translator for `notification.events.{$event}.{$attribute}` directly.
     * Event names contain dots — `account.activated` — and the translator
     * splits on those, so it would look for a nested `account` array that does
     * not exist and quietly return the key. Indexing keeps the lang file keyed
     * by the event name as it is actually written.
     *
     * A missing title falls back to the raw event name at the call site rather
     * than to an empty string. A visible `kyc.deadline_missed` in the bell is an
     * obvious bug someone fixes; a blank row is one nobody notices.
     */
    protected function line(string $event, string $attribute): ?string
    {
        $events = __('notification.events');

        if (! is_array($events)) {
            return null;
        }

        $line = $events[$event][$attribute] ?? null;

        return is_string($line) ? $line : null;
    }

    /**
     * Where the notification takes someone who acts on it.
     *
     * Null is a legitimate answer — "your account was activated" is news, not a
     * task, and a row that looks clickable but goes nowhere is worse than plain
     * text.
     */
    protected function href(string $event): ?string
    {
        return match ($event) {
            'kyc.deadline_approaching',
            'kyc.deadline_missed',
            'kyc.update_requested',
            'account.kyc_resubmission_requested' => route('kyc.history'),

            'account.suspended' => route('onboarding.status'),

            /*
             * Security alerts land where they can be acted on. "A new device
             * signed in" is only useful if the next click is the one that
             * changes the password and ends the other sessions.
             */
            'identity.new_device_sign_in',
            'identity.password_changed' => route('security.edit'),

            default => null,
        };
    }
}
