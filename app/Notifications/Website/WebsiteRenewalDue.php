<?php

namespace App\Notifications\Website;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A domain or hosting term is running out (§41, P5-11).
 *
 * Sent once per reminder stage rather than once a day, and it says the date:
 * "your domain is expiring" without one is a message that cannot be acted on.
 */
class WebsiteRenewalDue extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $website,
        public readonly string $websiteId,
        /** Either `domain` or `hosting`. */
        public readonly string $service,
        public readonly ?string $expiresAt,
        public readonly int $daysRemaining,
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
            ->subject(__('website.renewal.subject.'.$this->service, ['website' => $this->website]))
            ->line(__('website.renewal.body.'.$this->service, [
                'website' => $this->website,
                'days' => $this->daysRemaining,
            ]));

        if ($this->expiresAt !== null) {
            $message->line(__('website.renewal.expires_on', ['date' => $this->expiresAt]));
        }

        return $message->action(
            __('website.renewal.action'),
            route('websites.show', $this->websiteId),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => 'website.renewal_due',
            'website' => $this->website,
            'website_id' => $this->websiteId,
            'service' => $this->service,
            'expires_at' => $this->expiresAt,
            'days_remaining' => $this->daysRemaining,
        ];
    }
}
