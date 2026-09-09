<?php

namespace App\Notifications\Package;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Confirms a term has been cancelled (§8.2).
 *
 * Sent whoever did it. An account holder who cancelled deliberately gets a
 * receipt; one whose term was cancelled by an administrator finds out, which is
 * the case that actually matters — losing package features without being told
 * reads as the platform breaking.
 *
 * The recorded reason is not included: it is written for the audit trail, and an
 * internal note is not a message to its subject (§7.3).
 */
class SubscriptionCancelled extends Notification implements ShouldQueue
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
            ->subject(__('Your :package package has been cancelled', ['package' => $this->package]))
            ->line(__('This package and its features are no longer active on your account.'))
            ->line(__('You can choose a package again whenever you are ready.'))
            ->action(__('View packages'), route('packages.index'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => 'package.cancelled',
            'package' => $this->package,
        ];
    }
}
