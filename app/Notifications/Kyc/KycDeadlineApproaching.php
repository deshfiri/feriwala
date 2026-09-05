<?php

namespace App\Notifications\Kyc;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A KYC round is running out of time (§7.4).
 *
 * Non-optional under D20. This is the message that lets someone keep their
 * account: a warning nobody may switch off is the difference between a missed
 * deadline and a surprise restriction.
 */
class KycDeadlineApproaching extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $daysRemaining,
    ) {}

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
            ->subject(__('Your verification is due soon'))
            ->line(trans_choice(
                '{1}Your verification is due tomorrow.|[2,*]Your verification is due in :count days.',
                $this->daysRemaining,
                ['count' => $this->daysRemaining],
            ))
            ->action(__('Finish your verification'), route('kyc.create'))
            ->line(__('Completing it keeps your account moving.'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => 'kyc.deadline_approaching',
            'days_remaining' => $this->daysRemaining,
        ];
    }
}
