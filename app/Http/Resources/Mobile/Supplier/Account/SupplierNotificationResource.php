<?php

namespace App\Http\Resources\Mobile\Supplier\Account;

use App\Notifications\Supplier\SupplierLifecycleNotification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
 * One of a Supplier's own notifications, worded in the request language.
 *
 * @mixin DatabaseNotification
 */
class SupplierNotificationResource extends JsonResource
{
    public static $wrap = 'data';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $event = (string) ($this->data['event'] ?? 'unknown');
        $reference = $this->data['reference'] ?? null;

        return [
            /** Notification identifier, for the read endpoint. */
            'id' => $this->id,
            /** What happened, e.g. `supplier.approved`; the app may use it to choose where a tap leads. */
            'event' => $event,
            'title' => SupplierLifecycleNotification::line($event, 'title'),
            'description' => trim(
                SupplierLifecycleNotification::line($event, 'description')
                .(filled($reference) ? ' '.__('notification.reference', ['reference' => $reference]) : '')
            ),
            /** A reviewer's own note, when one was written for the supplier. */
            'note' => $this->data['note'] ?? null,
            'is_read' => $this->read_at !== null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}