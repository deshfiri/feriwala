<?php

namespace App\Notifications\Access;

use App\Domain\Access\Actions\InvitePlatformStaff;
use App\Domain\Supplier\Notifications\SupplierResetPassword;
use Illuminate\Auth\Notifications\ResetPassword as BaseResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * A brand-new platform staff member's own "set your password" link
 * (commit-order item 5's Platform Staff management).
 *
 * Reuses the `web` guard's existing password-reset broker and
 * `password_reset_tokens` table exactly as a self-service "forgot password"
 * would -- {@see InvitePlatformStaff} calls
 * `Password::broker('users')->createToken()` itself rather than
 * `sendResetLink()`, so this notification (not the base one every ordinary
 * reset uses) is the one actually sent, mirroring
 * {@see SupplierResetPassword}'s pattern
 * of overriding only the copy, not the underlying mechanism.
 *
 * An admin never chooses or sees this person's password (§6) -- there is
 * nothing to leak, because nothing was ever set on their behalf.
 */
class PlatformStaffInvited extends BaseResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        $url = url(route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false));

        return (new MailMessage)
            ->subject(__('You have been added to Feriwala'))
            ->line(__('An administrator has added you as platform staff on Feriwala.'))
            ->line(__('Set your password to sign in for the first time.'))
            ->action(__('Set Password'), $url)
            ->line(__('This link will expire in :count minutes.', ['count' => config('auth.passwords.users.expire')]))
            ->line(__('If you were not expecting this, contact support.'));
    }
}
