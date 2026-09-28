<?php

namespace App\Notifications\Kyc;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a business that a verification request has been withdrawn (§7.2).
 *
 * Non-optional under D20, and for a sharper reason than most: the request
 * may have been restricting what the business could do. Someone who stopped
 * taking wholesale orders because we asked for a document needs to know the
 * moment they can start again — otherwise they keep turning work away for a
 * requirement that no longer exists.
 *
 * Carries no reason. Why staff withdrew it is an internal matter (§7.3); what
 * the business needs is that there is nothing left to answer.
 */
class KycReverificationCancelled extends Notification implements ShouldQueue
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
            ->subject(__('No further verification needed'))
            ->line(__('The verification we asked you for is no longer required, and any limits it placed on your account have been lifted.'))
            ->line(__('There is nothing for you to do.'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['event' => 'kyc.reverification_cancelled'];
    }
}
