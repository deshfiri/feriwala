<?php

namespace App\Notifications\Account;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells someone their sign-in has been permanently closed (§6). Unlike
 * {@see IdentityLocked} or {@see IdentitySuspended}, `UserStatus::Closed` is
 * terminal -- there is no path back to Active, so the wording never
 * suggests one.
 */
class IdentityClosed extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Your Feriwala sign-in has been closed'))
            ->line(__('Your sign-in has been permanently closed and you will not be able to access Feriwala.'))
            ->line(__('Any sessions you had open have been ended.'))
            ->line(__('Contact support if you believe this is a mistake.'));
    }
}
