<?php

namespace App\Notifications\Account;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a partner which part of their application to correct (D39).
 *
 * Non-optional under D20, like every account-status message: an application
 * waiting on a correction nobody was told about looks exactly like one that
 * was ignored. Carries only what the partner is meant to read — the internal
 * reason stays on the decision record.
 */
class ApplicationCorrectionRequested extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<string>  $sections  personal | business | domain
     */
    public function __construct(
        public readonly array $sections,
        public readonly string $feedback,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('notification.events')['account.application_correction_requested']['title'] ?? 'Your application needs a correction')
            ->line($this->feedback)
            ->action(__('onboarding.actions.correct'), route('onboarding.status'))
            ->line(__('notification.events')['account.application_correction_requested']['description'] ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => 'account.application_correction_requested',
            'sections' => $this->sections,
            'feedback' => $this->feedback,
        ];
    }
}
