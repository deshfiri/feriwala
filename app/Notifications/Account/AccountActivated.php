<?php

namespace App\Notifications\Account;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells someone their account is active (§5.1, §44).
 *
 * Non-optional under D20. Activation is the moment the waiting ends, and an
 * applicant who has been checking a status page for a week should not have to
 * keep checking it.
 */
class AccountActivated extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly ?string $note = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        // Dashboard first, and not optional (D20). Mail can be missed,
        // filtered, or sent to an address nobody reads; the ERP entry is the
        // one the account holder is certain to see.
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject(__('Your account is now active'))
            ->line(__('Your Feriwala account has been approved and is ready to use.'));

        if ($this->note !== null && trim($this->note) !== '') {
            $message->line($this->note);
        }

        return $message->action(__('Go to your dashboard'), route('onboarding.status'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => 'account.activated',
            'note' => $this->note,
        ];
    }
}
