<?php

namespace App\Notifications\Package;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Confirms a renewal has gone through (§8.2, §8.4).
 *
 * §8.4 restores features "after successful renewal verification", and somebody
 * who paid while their term was in a grace period needs to know the restoring
 * has happened — otherwise they carry on assuming the account is at risk and
 * pay again.
 *
 * The new expiry is the useful part. "Renewed" without a date leaves the reader
 * with the same question they started with.
 */
class SubscriptionRenewed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $package,
        public readonly ?string $expiresAt,
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
            ->subject(__('Your :package package has been renewed', ['package' => $this->package]))
            ->line(__('Your renewal payment has been confirmed and your package is active.'));

        if ($this->expiresAt !== null) {
            $message->line(__('It now runs until :date.', ['date' => $this->expiresAt]));
        }

        return $message->action(__('View your subscription'), route('subscription.show'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => 'package.renewed',
            'package' => $this->package,
            'expires_at' => $this->expiresAt,
        ];
    }
}
