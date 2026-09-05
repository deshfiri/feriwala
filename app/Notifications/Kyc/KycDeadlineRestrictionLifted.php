<?php

namespace App\Notifications\Kyc;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A KYC deadline restriction has been lifted (§7.4).
 *
 * The other half of a restriction. Telling someone their account was restricted
 * and then never telling them it was restored leaves them assuming the worst
 * and contacting support to ask — so this is as mandatory as the notice that
 * took the access away.
 */
class KycDeadlineRestrictionLifted extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        // Dashboard first: it is the channel the account holder is looking at
        // when they wonder whether the restriction has gone (D20).
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Your account restriction has been lifted'))
            ->line(__('Thank you — your verification is complete and the restriction on your account has been removed.'))
            ->action(__('Go to your dashboard'), route('onboarding.status'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => 'kyc.deadline_restriction_lifted',
            'title' => __('Your account restriction has been lifted'),
            'body' => __('Your verification is complete and the restriction has been removed.'),
        ];
    }
}
