<?php

namespace App\Notifications\Account;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells an applicant what to correct (§7.3).
 *
 * Non-optional under D20 — KYC and account-status messages are not something a
 * user can switch off, because an application waiting silently for a correction
 * nobody was told about is indistinguishable from one that was ignored.
 *
 * Carries only the reviewer's applicant-facing feedback. The internal reason and
 * note stay on the decision record, where the account holder cannot reach them.
 */
class KycResubmissionRequested extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $feedback,
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
            ->subject(__('Your verification needs a correction'))
            ->line(__('We could not complete your verification with what you sent us.'))
            ->line($this->feedback)
            ->action(__('Update your documents'), route('kyc.create'))
            ->line(__('Once you resubmit, your application returns to the review queue.'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => 'account.kyc_resubmission_requested',
            'feedback' => $this->feedback,
        ];
    }
}
