<?php

namespace App\Notifications\Courier;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Notification;

/**
 * What has happened to a Partner's parcel, in the fewest safe words: booked,
 * picked up, in transit, delivered, delivery failed, returned.
 *
 * Only confirmed moves of the shipment's own status are announced -- never a raw
 * courier value, an internal review note, an error, or any customer detail. The
 * wording is chosen by the outcome alone and rendered in the reader's language
 * when they open the bell. Database only, idempotent through the caller's
 * deterministic id.
 */
class CourierPartnerUpdate extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string  $outcome  `booked`, `picked_up`, `in_transit`, `delivered`, `failed` or `returned`
     */
    public function __construct(
        public readonly string $outcome,
        public readonly string $reference,
        public readonly string $orderId,
    ) {}

    public function event(): string
    {
        return 'courier.partner.'.$this->outcome;
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        return $this->id === null || ! DatabaseNotification::query()->whereKey($this->id)->exists();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => $this->event(),
            'reference' => $this->reference,
            'order' => $this->orderId,
        ];
    }
}
