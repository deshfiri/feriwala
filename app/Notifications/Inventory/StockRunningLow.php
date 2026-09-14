<?php

namespace App\Notifications\Inventory;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A SKU in a warehouse has fallen to its low-stock threshold (§19, P3-29).
 *
 * For the platform staff who replenish stock, so it says what, where, how many
 * are left and against what threshold — "stock is low" without the figures is
 * a message nobody can act on — and it links straight to that stock's history.
 * Running out entirely is the same alert with a sharper event, because the
 * product has also just been taken off sale (P3-28).
 */
class StockRunningLow extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $stockItemId,
        public readonly string $sku,
        public readonly string $warehouse,
        public readonly int $available,
        public readonly int $threshold,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function event(): string
    {
        return $this->available === 0 ? 'inventory.stock_out' : 'inventory.stock_low';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $out = $this->available === 0;

        return (new MailMessage)
            ->subject(__($out ? 'inventory.alerts.out_subject' : 'inventory.alerts.low_subject', ['sku' => $this->sku]))
            ->line(__($out ? 'inventory.alerts.out_body' : 'inventory.alerts.low_body', [
                'sku' => $this->sku,
                'warehouse' => $this->warehouse,
                'available' => $this->available,
                'threshold' => $this->threshold,
            ]))
            ->action(__('inventory.alerts.action'), route('admin.inventory.stock.show', $this->stockItemId));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => $this->event(),
            'stock_item' => $this->stockItemId,
            'sku' => $this->sku,
            'warehouse' => $this->warehouse,
            'available' => $this->available,
            'threshold' => $this->threshold,
        ];
    }
}
