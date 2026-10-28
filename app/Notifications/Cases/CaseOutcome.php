<?php

namespace App\Notifications\Cases;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Notification;

/**
 * What happened to a Partner's return, exchange or damage (D35), in the fewest
 * safe words: approved, refused, parcel received, inspected, charged, refunded,
 * dispatched.
 *
 * The wording is chosen by the outcome alone and rendered in the reader's own
 * language when they open the bell, so what is stored is only the outcome, the
 * kind of case, its reference and its public id. No staff note, no staff name, no
 * customer detail, and no evidence is ever carried — the link goes to the Partner's
 * own self-scoped page, which shows what they may see. Database only.
 *
 * Idempotent: the caller derives each recipient's id from the case, the outcome
 * and (for a correction) its revision, and a row already stored under it is not
 * written again.
 */
class CaseOutcome extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string  $kind  `return`, `exchange` or `damage`
     */
    public function __construct(
        public readonly string $outcome,
        public readonly string $kind,
        public readonly string $reference,
        public readonly string $caseId,
    ) {}

    public function event(): string
    {
        return 'case.outcome.'.$this->outcome;
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
            'kind' => $this->kind,
            'reference' => $this->reference,
            'case' => $this->caseId,
        ];
    }
}
