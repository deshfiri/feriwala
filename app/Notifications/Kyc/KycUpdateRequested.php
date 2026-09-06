<?php

namespace App\Notifications\Kyc;

use App\Domain\Kyc\Models\KycSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A trading business is asked for fresh KYC (§7.2).
 *
 * Non-optional under D20 — an account-status message, and one that could be
 * switched off is one an account can miss and then be restricted for missing
 * (§7.4).
 *
 * Carries the reviewer's **instructions**, never the internal reason. The
 * reason is why we asked, recorded for colleagues and auditors; the instruction
 * is what the account holder has to do. Sending the wrong one puts a note meant
 * for staff in front of a customer.
 */
class KycUpdateRequested extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly KycSubmission $submission,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        // Dashboard first, and not optional (D20). Mail can be missed,
        // filtered, or sent to an address nobody reads.
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject(__('We need updated verification documents'))
            ->line(__('We have asked for an update to your account verification.'))
            ->line((string) $this->submission->request_instructions);

        if ($this->submission->deadline_at !== null) {
            $message->line(__('Please complete this by :date.', [
                'date' => $this->submission->deadline_at->toFormattedDayDateString(),
            ]));
        }

        return $message
            ->action(__('Update your documents'), route('kyc.create'))
            // Said plainly, because the alternative — a business discovering it
            // mid-order — is worse than being told now.
            ->line(__('Your account keeps trading in the meantime.'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => 'kyc.update_requested',
            'round' => $this->submission->round,
            'instructions' => $this->submission->request_instructions,
            'deadline_at' => $this->submission->deadline_at?->toIso8601String(),
        ];
    }
}
