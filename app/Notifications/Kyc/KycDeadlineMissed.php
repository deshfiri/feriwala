<?php

namespace App\Notifications\Kyc;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A KYC deadline has passed (§7.4).
 *
 * Non-optional under D20. It says what has happened and what undoes it — a
 * notice that a business has been restricted without saying how to lift it
 * leaves someone stuck rather than informed.
 */
class KycDeadlineMissed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        /** Whether the account was restricted, as opposed to simply left blocked. */
        public readonly bool $accountRestricted,
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
        $message = (new MailMessage)
            ->subject(__('Your verification is overdue'));

        $message->line($this->accountRestricted
            ? __('Your account has been restricted because the verification deadline passed.')
            : __('The verification deadline has passed, so your account cannot be activated yet.'));

        return $message
            ->action(__('Complete your verification'), route('kyc.create'))
            ->line(__('Sending your documents lifts this — nothing else is needed from you.'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => 'kyc.deadline_missed',
            'account_restricted' => $this->accountRestricted,
        ];
    }
}
