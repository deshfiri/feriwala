<?php

namespace App\Notifications\Account;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells someone their account was used from a device it has not seen (§6).
 *
 * Non-optional under D20, and the clearest case for it there is: a stolen
 * password is invisible to the person it was stolen from, and this message is
 * usually the only thing that ever tells them. Somebody who reads it and says
 * "that was me" has lost four seconds; somebody who reads it and says "that was
 * not me" has just found out in time to do something.
 *
 * Mail as well as the dashboard, deliberately. An attacker who is already signed
 * in can read the bell; they cannot read the mailbox.
 *
 * It states what happened and nothing about what to conclude. The address is
 * included because it is occasionally the thing that identifies the sign-in —
 * but no claim is made about where it is, because nothing here looks that up.
 */
class NewDeviceSignIn extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $device,
        public readonly ?string $ipAddress,
        public readonly string $signedInAt,
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
            ->subject(__('A new device signed in to your account'))
            ->line(__('Your Feriwala account was signed in to from a device we have not seen before.'))
            ->line(__('Device: :device', ['device' => $this->device]))
            ->line(__('IP address: :ip', ['ip' => $this->ipAddress ?? __('Unknown')]))
            ->line(__('Time: :time', ['time' => $this->signedInAt]))
            ->line(__('If this was you, no action is needed.'))
            ->line(__('If it was not, change your password now and sign out the other devices.'))
            ->action(__('Review your security settings'), route('security.edit'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => 'identity.new_device_sign_in',
            'device' => $this->device,
            'ip_address' => $this->ipAddress,
            'signed_in_at' => $this->signedInAt,
        ];
    }
}
