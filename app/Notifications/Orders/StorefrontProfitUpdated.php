<?php

namespace App\Notifications\Orders;

use App\Domain\Order\Models\PartnerStorefrontProfit;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * A Storefront profit moved on: pending with its release time, credited, taken
 * back, or waiting for staff.
 *
 * Carries the order's reference, the amount and the time — nothing about the
 * customer, whose details stay on the order behind its own screens.
 */
class StorefrontProfitUpdated extends Notification implements ShouldQueue
{
    use Queueable;

    public const PENDING = 'orders.storefront_profit_pending';

    public const RELEASED = 'orders.storefront_profit_released';

    public const REVERSED = 'orders.storefront_profit_reversed';

    public const REVIEW = 'orders.storefront_profit_review';

    public readonly string $profit;

    public readonly string $order;

    public readonly string $reference;

    /** @var array<string, mixed>|null */
    public readonly ?array $amount;

    public readonly ?string $releaseAt;

    /**
     * @param  array<string, mixed>|null  $amount  a serialised Money
     */
    public function __construct(
        PartnerStorefrontProfit $profit,
        public readonly string $event,
        ?array $amount = null,
    ) {
        $this->profit = $profit->public_id;
        $this->order = $profit->order->public_id;
        $this->reference = $profit->order->reference;
        $this->amount = $amount;
        $this->releaseAt = $profit->release_at?->toIso8601String();
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => $this->event,
            'profit' => $this->profit,
            'order' => $this->order,
            'reference' => $this->reference,
            'amount' => $this->amount,
            'release_at' => $this->releaseAt,
        ];
    }
}
