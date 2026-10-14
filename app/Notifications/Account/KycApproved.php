<?php

namespace App\Notifications\Account;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells an applicant their verification was approved (§7.3).
 *
 * Says nothing about what was submitted or how it was judged: no document names,
 * no values, no reviewer, no note. Approval opens package selection and the rest
 * of onboarding, which is all the applicant needs to be told.
 */
class KycApproved extends Notification implements ShouldQueue
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
            ->subject(__('Your verification has been approved'))
            ->line(__('Thank you. We have completed your verification.'))
            ->action(__('Continue'), route('onboarding.status'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['event' => 'account.kyc_approved'];
    }
}
