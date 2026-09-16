<?php

namespace App\Notifications\Website;

use App\Domain\Website\Enums\WebsiteStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A partner's storefront has changed state (§16.4).
 *
 * Sent for the moves that change what their customers see — activated,
 * suspended, disabled, in grace, package expired — and not for the rest: a
 * message for every internal step would train people to ignore all of them.
 *
 * Carries the **public** note only. Whatever a reviewer wrote for the platform
 * about this partner stays on the platform.
 */
class WebsiteStatusChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $website,
        public readonly string $websiteId,
        public readonly WebsiteStatus $status,
        public readonly ?string $publicNote = null,
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
            ->subject(__('Your website :website is now :status', [
                'website' => $this->website,
                'status' => __('website.statuses.'.$this->status->value),
            ]))
            ->line(__('website.notifications.'.$this->status->value, ['website' => $this->website]));

        if ($this->publicNote !== null) {
            $message->line($this->publicNote);
        }

        return $message->action(__('website.notifications.action'), route('websites.show', $this->websiteId));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => 'website.status_changed',
            'website' => $this->website,
            'website_id' => $this->websiteId,
            'status' => $this->status->value,
            'note' => $this->publicNote,
        ];
    }
}
