<?php

namespace App\Notifications\Package;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells an account it has been given a package (§8.3).
 *
 * Their limits have changed and they did not ask for it, which is precisely why
 * it needs saying — an account that finds out by hitting a different limit than
 * it had yesterday will assume something is broken.
 *
 * The end date is the important half. A granted term that quietly runs out is
 * the same surprise again, in the other direction.
 *
 * The administrator's reason is not included: it is written for the audit trail,
 * and an internal note is not a message to its subject (§7.3).
 */
class PackageAssigned extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $package,
        public readonly ?string $startsAt,
        public readonly ?string $expiresAt,
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
            ->subject(__('Your account has been given the :package package', ['package' => $this->package]))
            ->line(__('An administrator has assigned this package to your account.'));

        if ($this->startsAt !== null) {
            $message->line(__('It applies from :date.', ['date' => $this->startsAt]));
        }

        $message->line($this->expiresAt === null
            ? __('It does not expire.')
            : __('It runs until :date.', ['date' => $this->expiresAt]));

        return $message->action(__('View your subscription'), route('subscription.show'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => 'package.assigned',
            'package' => $this->package,
            'starts_at' => $this->startsAt,
            'expires_at' => $this->expiresAt,
        ];
    }
}
