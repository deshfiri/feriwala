<?php

namespace App\Notifications\Orders;

use App\Domain\Order\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * A customer paid for an order on the owner's website (§17, §30, P5-23).
 *
 * The order's reference, its status — paid, or held for Feriwala to resolve —
 * and its total. Nothing about the customer: their details are on the order,
 * behind the website's own screens.
 */
class WebsiteOrderPaid extends Notification implements ShouldQueue
{
    use Queueable;

    public readonly string $order;

    public readonly string $reference;

    public readonly string $status;

    /** @var array<string, mixed> */
    public readonly array $total;

    public function __construct(Order $order)
    {
        $this->order = $order->public_id;
        $this->reference = $order->reference;
        $this->status = $order->status->value;
        $this->total = $order->total_minor->jsonSerialize();
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
            'event' => 'orders.website_order_paid',
            'order' => $this->order,
            'reference' => $this->reference,
            'status' => $this->status,
            'total' => $this->total,
        ];
    }
}
