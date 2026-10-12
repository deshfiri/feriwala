<?php

namespace App\Notifications\Staff;

use App\Domain\Notification\Enums\StaffQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Notification;

/**
 * Something arrived in a staff queue and is waiting on a person (§33.2).
 *
 * One notification for every queue, keyed by {@see StaffQueue}, because they all
 * say the same thing — "a new X is waiting" — and differ only in wording and
 * destination, both of which the queue already knows. Database only: a mail or
 * SMS per submission would bury the reviewers it is meant to help; the bell and
 * the dashboard count are the signal.
 *
 * Carries the submission's own reference, never the submitter's details — those
 * are behind the screen it links to, under that screen's own permission.
 *
 * Idempotent: the caller gives each recipient a deterministic id, and a row that
 * already exists under it is not written again — so a retried queue job, or the
 * same submission announced twice, leaves one bell entry, not two.
 */
class WorkItemSubmitted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly StaffQueue $staffQueue,
        public readonly ?string $reference = null,
    ) {}

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
            'event' => $this->staffQueue->event(),
            'reference' => $this->reference,
        ];
    }
}
