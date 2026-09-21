<?php

namespace App\Domain\Supplier\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail as BaseVerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\URL;

/**
 * A Supplier's own email verification notice (D25).
 *
 * The base {@see BaseVerifyEmail} notification signs its link against the
 * `verification.verify` route name, which belongs to the Client/Partner
 * domain via Fortify (`config/fortify.php` guard `web`). This override signs
 * against `supplier.verification.verify` instead, so a Supplier's link can
 * never be used to verify — or be confused with — a Client/Partner identity.
 */
class SupplierVerifyEmail extends BaseVerifyEmail
{
    public function toMail($notifiable): MailMessage
    {
        $url = $this->verificationUrl($notifiable);

        return (new MailMessage)
            ->subject(__('Verify your Supplier email address'))
            ->line(__('Please click the button below to verify your Supplier account email address.'))
            ->action(__('Verify Email Address'), $url)
            ->line(__('If you did not create a Supplier account, no further action is required.'));
    }

    protected function verificationUrl($notifiable): string
    {
        return URL::temporarySignedRoute(
            'supplier.verification.verify',
            now()->addMinutes(config('auth.verification.expire', 60)),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ],
        );
    }
}
