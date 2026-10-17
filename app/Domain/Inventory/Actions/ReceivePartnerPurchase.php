<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\Data\MovementContext;
use App\Domain\Inventory\PartnerStock;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Models\Order;

/**
 * A paid wholesale purchase gives the Partner its units (D34).
 *
 * One movement per order line, keyed by the line, so the same payment settling
 * twice — a repeated callback, a backfill run over an order that was already
 * credited — adds nothing the second time. Only a wholesale order counts: a
 * storefront order sells units, it does not buy them.
 */
class ReceivePartnerPurchase
{
    public function __construct(
        protected PartnerStock $stock,
    ) {}

    /**
     * @return int how many units were credited by this call
     */
    public function handle(Order $order): int
    {
        if ($order->source !== OrderSource::ErpWholesale || $order->paid_at === null || $order->cancelled_at !== null) {
            return 0;
        }

        $account = $order->businessAccount;
        $credited = 0;

        foreach ($order->items()->get() as $item) {
            // A line whose Product has since been force deleted keeps only its snapshot.
            if ($item->product_id === null || $item->quantity < 1) {
                continue;
            }

            $key = 'wholesale-line:'.$item->public_id;

            $movement = $this->stock->receive(
                $account,
                $item->product_id,
                $item->product_variant_id,
                $item->quantity,
                new MovementContext(
                    reason: 'Bought on '.$order->reference.'.',
                    sourceType: 'order_item',
                    sourceId: $item->id,
                    idempotencyKey: $key,
                ),
            );

            // A retried line returns the movement it already wrote.
            if ($movement->wasRecentlyCreated) {
                $credited += $item->quantity;
            }
        }

        return $credited;
    }
}
