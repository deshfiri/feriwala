<?php

namespace App\Domain\Order\Actions;

use App\Domain\Inventory\Data\MovementContext;
use App\Domain\Inventory\Enums\PartnerStockMovementType;
use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Inventory\Exceptions\InventoryRefused;
use App\Domain\Inventory\PartnerStock;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Models\Order;

/**
 * Delivery: the units dispatch committed are now sold (D34).
 *
 * This is a move between two buckets of units the Partner no longer has to offer —
 * processing to sold — not a second deduction: nothing leaves the Partner's
 * stock here, and `available` is untouched. Keyed by the order line, so a repeat
 * (a duplicate courier webhook, a re-run) moves nothing twice.
 */
class MarkPartnerStockSold
{
    public function __construct(
        protected PartnerStock $stock,
    ) {}

    public function handle(Order $order): void
    {
        // A storefront order, or the replacement an exchange sends (D35): both are
        // filled from, and consume, the Partner's own units.
        if (! in_array($order->source, [OrderSource::Website, OrderSource::Exchange], true)) {
            return;
        }

        foreach ($order->items()->with('stockReservation.partnerStock')->get() as $item) {
            $reservation = $item->stockReservation;

            if ($reservation === null || ! $reservation->isPartnerSourced() || $reservation->partnerStock === null) {
                continue;
            }

            try {
                $this->stock->move(
                    $reservation->partnerStock,
                    StockBucket::Processing,
                    StockBucket::Sold,
                    $item->quantity,
                    PartnerStockMovementType::Adjustment,
                    new MovementContext(
                        reason: 'Delivered on '.$order->reference.'.',
                        sourceType: 'order_item',
                        sourceId: $item->id,
                        idempotencyKey: 'delivered-line:'.$item->public_id,
                    ),
                );
            } catch (InventoryRefused) {
                // Nothing committed to move (never dispatched through the workflow): leave it.
            }
        }
    }
}
