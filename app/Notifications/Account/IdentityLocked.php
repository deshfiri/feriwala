<?php

namespace App\Notifications\Account;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells someone their sign-in has been locked (§6).
 *
 * Non-optional under D20. Losing access without being told leaves a person
 * retrying a password that is not the problem, which looks to them like the
 * platform is broken and looks to the throttle like an attack.
 *
 * **The internal reason is never sent.** It is written for the audit trail, and
 * an assessment recorded for staff is not a message to the person it is about
 * (§7.3). The message says what happened and how to ask about it, which is all
 * this side of the decision can honestly offer.
 */
class IdentityLocked extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        // Mail carries this one. The dashboard entry is written too, but a
        // locked person cannot open the dashboard to read it — it is there for
        // when access comes back.
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Your Feriwala sign-in has been locked'))
            ->line(__('Your sign-in has been locked and you will not be able to access Feriwala until it is restored.'))
            ->line(__('Any sessions you had open have been ended.'))
            ->line(__('Contact support to find out what is needed to restore access.'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['event' => 'identity.locked'];
    }
}
