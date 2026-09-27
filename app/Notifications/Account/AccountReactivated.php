<?php

namespace App\Notifications\Account;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells an account holder their suspension has been lifted (§5.3).
 *
 * The other half of {@see AccountSuspended}, and non-optional for the same
 * reason under D20: someone told they could not trade must be told when they
 * can again. A business that quietly regains access learns it by guessing, and
 * in the meantime turns away work it could have taken.
 *
 * The internal reason is never included — only a note the reviewer deliberately
 * wrote for the account holder (§7.3).
 */
class AccountReactivated extends Notification implements ShouldQueue
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
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject(__('Your account is active again'))
            ->line(__('The suspension on your Feriwala account has been lifted and you can trade again.'));

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
            'event' => 'account.reactivated',
            'note' => $this->note,
        ];
    }
}
