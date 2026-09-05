<?php

namespace App\Notifications\Account;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells an account holder their account has been suspended (§5.3).
 *
 * Non-optional under D20. Losing the ability to trade without being told is the
 * clearest case there is for a notification a user cannot switch off.
 *
 * The internal reason is never included. Only a note the reviewer deliberately
 * wrote for the account holder is sent, and when there is none the message says
 * how to ask rather than inventing an explanation.
 */
class AccountSuspended extends Notification implements ShouldQueue
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
            ->subject(__('Your account has been suspended'))
            ->line(__('Your Feriwala account has been suspended and cannot be used for now.'));

        if ($this->note !== null && trim($this->note) !== '') {
            $message->line($this->note);
        }

        return $message
            // Suspension is reversible (§5.3). Saying so is the difference
            // between a message someone can act on and one that reads as final.
            ->line(__('If you believe this is a mistake, contact support and we will review it.'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => 'account.suspended',
            'note' => $this->note,
        ];
    }
}
