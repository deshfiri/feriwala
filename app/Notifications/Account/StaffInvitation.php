<?php

namespace App\Notifications\Account;

use App\Domain\Account\Models\AccountInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Invites someone to work in a business account (§8.1).
 *
 * Mail only, and deliberately: the person being invited has no dashboard in this
 * account yet — that is the whole point of the invitation — so there is nowhere
 * else to put it. Every other account notification goes to the database channel
 * first under D20; this one is the exception that proves why.
 *
 * The message names the business and the person inviting, because an unexplained
 * invitation to an ERP reads like phishing.
 */
class StaffInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly AccountInvitation $invitation,
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
        $account = $this->invitation->businessAccount;
        $inviter = $this->invitation->invitedBy;

        $message = (new MailMessage)
            ->subject(__('You have been invited to join :account', ['account' => $account->name]));

        if ($inviter !== null) {
            $message->line(__(':inviter has invited you to join :account as :role.', [
                'inviter' => $inviter->name,
                'account' => $account->name,
                'role' => $this->invitation->role->label(),
            ]));
        } else {
            $message->line(__('You have been invited to join :account as :role.', [
                'account' => $account->name,
                'role' => $this->invitation->role->label(),
            ]));
        }

        return $message
            ->action(__('Accept invitation'), route('staff.invitation.show', $this->invitation->token))
            ->line(__('This invitation expires on :date.', [
                'date' => $this->invitation->expires_at->toFormattedDayDateString(),
            ]))
            ->line(__('If you were not expecting this, you can ignore this email.'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => 'account.staff_invited',
            'account' => $this->invitation->businessAccount->name,
            'role' => $this->invitation->role->value,
        ];
    }
}
