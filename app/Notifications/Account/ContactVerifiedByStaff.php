<?php

namespace App\Notifications\Account;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells someone a member of staff confirmed their email or mobile for them.
 *
 * Sent to the person whose contact was confirmed (a Client/Partner owner or a
 * Supplier), so a confirmation they did not ask for is never silent. Names the
 * channel, never the staff member or the internal reason.
 */
class ContactVerifiedByStaff extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $channel,
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
            ->subject(__('managed_accounts.verified_mail.subject'))
            ->line(__('managed_accounts.verified_mail.line', ['channel' => __('managed_accounts.channels.'.$this->channel)]));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['event' => 'contact.verified_by_staff', 'channel' => $this->channel];
    }
}
