<?php

namespace App\Notifications\Staff;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Notification;

/**
 * A return, exchange or damage has reached a step that one of the staff holding
 * its permission has to take (D35): inspect the goods, decide who bears the loss,
 * post it, send the item.
 *
 * Carries which step, which kind of case, the case's own reference and its public
 * id — enough to word the bell and open the workspace. Never a customer's name,
 * number or address, an evidence file's name or path, or a staff note: those are
 * behind the workspace, under its own permission. Database only.
 *
 * Idempotent like every queue notification: the caller gives each recipient a
 * deterministic id, and a row already stored under it is not written again.
 */
class CaseStepRequired extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string  $kind  `return`, `exchange` or `damage`
     */
    public function __construct(
        public readonly string $step,
        public readonly string $kind,
        public readonly string $reference,
        public readonly string $caseId,
    ) {}

    public function event(): string
    {
        return 'case.step.'.$this->step;
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
