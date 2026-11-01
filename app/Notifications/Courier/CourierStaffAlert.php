<?php

namespace App\Notifications\Courier;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Notification;

/**
 * A courier booking or parcel needs a member of staff to look: a booking failed,
 * its outcome is not known, or Steadfast reported something Banij cannot act on.
 *
 * Carries which alert, the order's own reference and its public id -- enough to
 * word the bell and open the order. Never the customer's name, number or
 * address, a courier credential, an error body or Steadfast's raw payload.
 * Database only, idempotent like every queue notification: the caller gives each
 * recipient a deterministic id, and a row already stored under it is not written
 * again.
 */
class CourierStaffAlert extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string  $alert  `booking_failed`, `booking_uncertain`, `status_review` or `status_unknown`
     */
    public function __construct(
        public readonly string $alert,
        public readonly string $reference,
        public readonly string $orderId,
    ) {}

    public function event(): string
    {
        return 'courier.staff.'.$this->alert;
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
