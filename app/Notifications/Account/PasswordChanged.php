<?php

namespace App\Notifications\Account;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells someone their password changed (§6).
 *
 * The one message that catches an account takeover after the fact. Somebody who
 * changes their own password already knows; somebody who reads this and did not
 * change it has just found out that whoever did has their mailbox or their
 * session, and has a few minutes to do something about it.
 *
 * Non-optional under D20, and by mail above all: an attacker who has just taken
 * the account can read the dashboard and cannot read the inbox.
 *
 * It never contains the password, old or new, and never says what changed
 * beyond the fact of it (§42).
 */
class PasswordChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        /** Whether this came from a reset link rather than the security screen. */
        public readonly bool $viaReset = false,
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
            ->subject(__('Your Feriwala password was changed'))
            ->line($this->viaReset
                ? __('Your password was changed using a reset link.')
                : __('Your password was changed from your security settings.'));

        return $message
            ->line(__('Every other device signed in to this account has been signed out.'))
            ->line(__('If you did not do this, contact support immediately — somebody else has access to your account or your email.'))
            ->action(__('Review your security settings'), route('security.edit'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => 'identity.password_changed',
            'via_reset' => $this->viaReset,
        ];
    }
}
