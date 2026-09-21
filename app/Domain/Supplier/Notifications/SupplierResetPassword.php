<?php

namespace App\Domain\Supplier\Notifications;

use Illuminate\Auth\Notifications\ResetPassword as BaseResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * A Supplier's own password reset notice (D25).
 *
 * The base {@see BaseResetPassword} notification links to the `password.reset`
 * route, which belongs to the Client/Partner domain. This override links to
 * `supplier.password.reset` instead, against the Supplier's own broker and
 * token table (`supplier_password_reset_tokens`, `config/auth.php`).
 */
class SupplierResetPassword extends BaseResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        $url = url(route('supplier.password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false));

        return (new MailMessage)
            ->subject(__('Reset your Supplier password'))
            ->line(__('You are receiving this email because we received a password reset request for your Supplier account.'))
            ->action(__('Reset Password'), $url)
            ->line(__('This password reset link will expire in :count minutes.', ['count' => config('auth.passwords.suppliers.expire')]))
            ->line(__('If you did not request a password reset, no further action is required.'));
    }
}
