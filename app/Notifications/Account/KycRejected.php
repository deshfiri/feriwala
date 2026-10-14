<?php

namespace App\Notifications\Account;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells an applicant their verification was not approved (§7.3).
 *
 * Carries only the reviewer's applicant-facing feedback. The internal reason and
 * note stay on the decision record, and no document or value is mentioned.
 */
class KycRejected extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly ?string $feedback = null,
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
        $message = (new MailMessage)
            ->subject(__('Your verification was not approved'))
            ->line(__('We could not approve your verification.'));

        if ($this->feedback !== null && trim($this->feedback) !== '') {
            $message->line($this->feedback);
        }

        return $message->action(__('See your status'), route('onboarding.status'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['event' => 'account.kyc_rejected', 'feedback' => $this->feedback];
    }
}
