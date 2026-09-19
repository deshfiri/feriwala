<?php

namespace App\Domain\Order\Actions;

use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderStatusChange;
use App\Domain\Website\Actions\PublishWebsiteEvent;
use App\Domain\Website\Enums\WebhookEvent;
use App\Domain\Website\Models\Website;

/**
 * Tell a website its order moved (contract §7.1, §7.3, P5-23).
 *
 * Every move of a website order's status — paid, held, cancelled, and whatever
 * fulfilment adds later — is written as a delivery through the website's one
 * webhook endpoint, in the transaction that made the move, and sent after it
 * commits: the delivery system retries it, dead-letters it and lets the owner
 * retry it like any other.
 *
 * `order.status_changed` for every move, and `order.cancelled` as well when the
 * move is a cancellation. **A hint, never the order**: its identifiers, the move
 * and when it happened. The storefront reads the rest back through the signed
 * API, so a lost or late webhook costs freshness, never correctness (§7.5).
 */
class AnnounceWebsiteOrderStatus
{
    public function __construct(
        protected PublishWebsiteEvent $events,
    ) {}

    public function handle(Order $order, OrderStatusChange $change): void
    {
        if ($order->website_id === null) {
            return;
        }

        /** @var Website|null $website */
        $website = Website::query()->find($order->website_id);

        if ($website === null) {
            return;
        }

        $data = [
            'order' => [
                'id' => $order->public_id,
                'reference' => $order->reference,
                'storefront_order_reference' => $order->storefront_order_reference,
                'status' => $change->new_status->value,
                'previous_status' => $change->previous_status?->value,
                'updated_at' => $change->changed_at->toIso8601String(),
            ],
        ];

        $this->events->handle($website, WebhookEvent::OrderStatusChanged, $data, 'order', $order->id);

        if ($change->new_status === OrderStatus::Cancelled) {
            $this->events->handle($website, WebhookEvent::OrderCancelled, $data, 'order', $order->id);
        }
    }
}
