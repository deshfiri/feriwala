<?php

namespace App\Notifications\Account;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells someone their sign-in works again (§6).
 *
 * The other half of {@see IdentityLocked}, and just as non-optional: somebody
 * whose access was restored without being told carries on assuming it was not,
 * and the lock effectively never ends.
 */
class IdentityUnlocked extends Notification implements ShouldQueue
{
    use Queueable;

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
            ->subject(__('Your Feriwala sign-in has been restored'))
            ->line(__('Your sign-in has been restored and you can use Feriwala again.'))
            ->action(__('Sign in'), route('login'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['event' => 'identity.unlocked'];
    }
}
