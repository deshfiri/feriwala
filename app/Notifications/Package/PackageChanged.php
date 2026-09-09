<?php

namespace App\Notifications\Package;

use App\Domain\Package\Enums\SubscriptionSource;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Confirms a move to another package (§8.3).
 *
 * The **effective date** is the part worth sending. An upgrade applies at once
 * and a downgrade at the end of the current term, and somebody who does not
 * know which has just been told their limits changed without being told when.
 */
class PackageChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $package,
        public readonly SubscriptionSource $direction,
        public readonly ?string $effectiveFrom,
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
            ->subject(__('Your package has changed to :package', ['package' => $this->package]))
            ->line($this->direction === SubscriptionSource::Upgrade
                ? __('Your upgrade has been confirmed and applies now.')
                : __('Your package change has been confirmed and applies at the end of your current term.'));

        if ($this->effectiveFrom !== null) {
            $message->line(__('Effective from :date.', ['date' => $this->effectiveFrom]));
        }

        return $message->action(__('View your subscription'), route('subscription.show'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => 'package.'.$this->direction->value,
            'package' => $this->package,
            'effective_from' => $this->effectiveFrom,
        ];
    }
}
