<?php

namespace App\Notifications\Package;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells an account its package has run out (§8.4).
 *
 * The features are gone as of now, and the message says what to do rather than
 * only what happened — §8.4 keeps payment and renewal open after expiry
 * precisely so this has somewhere to point.
 *
 * It does not threaten. An account that lets a term lapse has not done anything
 * wrong, and the thing that restores it is one click away.
 */
class SubscriptionExpired extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $package,
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
            ->subject(__('Your :package package has expired', ['package' => $this->package]))
            ->line(__('Your package term has ended and its features are no longer available.'))
            ->line(__('Renewing restores them straight away.'))
            ->action(__('Renew now'), route('subscription.renew.show'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => 'package.expired',
            'package' => $this->package,
        ];
    }
}
