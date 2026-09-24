<?php

namespace App\Domain\Order\Actions;

use App\Domain\Order\Enums\ReturnRefundState;
use App\Domain\Order\Models\OrderReturn;
use App\Domain\Order\Models\OrderReturnItem;
use App\Domain\Website\Actions\PublishWebsiteEvent;
use App\Domain\Website\Enums\WebhookEvent;
use App\Domain\Website\Models\Website;

/**
 * Tell a website where its customer's return has got to (contract §7.1, §7.3,
 * P6-12).
 *
 * `return.status_changed` for every move, and `refund.completed` as well once
 * the money has actually gone back — never when it was merely approved, which
 * is the difference a customer notices.
 *
 * Written through the delivery system every other event uses, in the
 * transaction that made the move, and sent once it commits. Keyed to the
 * return, so a burst — a decision and a receipt moments apart — updates the
 * pending delivery rather than queueing a second.
 *
 * **A hint, never the return.** Its identifiers, where it stands, what is
 * coming back and how much money. No warehouse, no disposition, no staff note:
 * where Feriwala keeps its stock is not the shop's business (D12).
 */
class AnnounceReturnStatus
{
    public function __construct(
        protected PublishWebsiteEvent $events,
    ) {}

    public function handle(OrderReturn $return): void
    {
        if ($return->website_id === null) {
            return;
        }

        /** @var Website|null $website */
        $website = Website::query()->find($return->website_id);

        if ($website === null) {
            return;
        }

        $return->loadMissing(['items.orderItem', 'order']);

        $data = [
            'return' => [
                'id' => $return->public_id,
                'reference' => $return->reference,
                'status' => $return->status->value,
                'reason' => $return->reason->value,
                'refund_state' => $return->refund_state->value,
                'refund_amount' => $return->refund_amount?->jsonSerialize(),
                'updated_at' => $return->updated_at?->toIso8601String(),
                'lines' => $return->items->map(fn (OrderReturnItem $item) => [
                    'sku' => $item->orderItem->sku,
                    'quantity' => $item->quantity,
                    'approved_quantity' => $item->approved_quantity,
                    'received_quantity' => $item->received_quantity,
                ])->all(),
            ],
            'order' => [
                'id' => $return->order->public_id,
                'reference' => $return->order->reference,
                'storefront_order_reference' => $return->order->storefront_order_reference,
            ],
        ];

        $this->events->handle($website, WebhookEvent::ReturnStatusChanged, $data, 'order_return', $return->id);

        if ($return->refund_state === ReturnRefundState::Completed) {
            $this->events->handle($website, WebhookEvent::RefundCompleted, $data, 'order_return', $return->id);
        }
    }
}
