<?php

namespace App\Notifications\Package;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Warns that a term is running out, or has and is inside its grace (§8.4).
 *
 * One message with two readings, because the difference is a date and a degree
 * of urgency rather than a different event — and splitting it would mean two
 * classes that have to be kept saying the same thing.
 *
 * §8.4 asks for a dashboard notification, and the grace period exists so that a
 * late renewal is not a severed account. Saying **when** the grace ends is the
 * whole value: "your package has expired" without a date reads as final.
 */
class SubscriptionExpiring extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $package,
        public readonly ?string $endsAt,
        public readonly bool $inGracePeriod,
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
            ->subject($this->inGracePeriod
                ? __('Your :package package has ended — renew to restore it', ['package' => $this->package])
                : __('Your :package package is due for renewal', ['package' => $this->package]));

        $message->line($this->inGracePeriod
            ? __('Your package term has ended and your account is inside its grace period.')
            : __('Your package term is coming to an end.'));

        if ($this->endsAt !== null) {
            $message->line($this->inGracePeriod
                ? __('Renew before :date to keep full access.', ['date' => $this->endsAt])
                : __('It ends on :date.', ['date' => $this->endsAt]));
        }

        return $message->action(__('Renew now'), route('subscription.renew.show'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => $this->inGracePeriod
                ? 'package.grace_period'
                : 'package.renewal_due',
            'package' => $this->package,
            'ends_at' => $this->endsAt,
        ];
    }
}
