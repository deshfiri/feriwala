<?php

namespace App\Domain\Order\Actions;

use App\Domain\Order\Enums\OrderDeliveryStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;

/**
 * What a move on the order's delivery axis means for a Non-Conditional
 * order's reseller-earning settlement (D-new).
 *
 * Reaching {@see OrderDeliveryStatus::Delivered} records that fact against
 * every line of a Non-Conditional order, through the same idempotent {@see
 * EvaluateOrderProceedsEligibility} every other caller uses. This alone never
 * credits anything — only once the COD amount is also confirmed collected,
 * through the separate {@see RecordCodCollection} action, does a line
 * actually settle. A Conditional order has no settlement row at all: the
 * reseller already paid upfront, so there is nothing here to recover or earn.
 */
class SynchronizeOrderProceedsWithDelivery
{
    public function __construct(
        protected EvaluateOrderProceedsEligibility $eligibility,
        protected SynchronizeOnlineCollectionWithPayment $onlineCollection,
    ) {}

    public function handle(Order $order, OrderDeliveryStatus $to): void
    {
        if ($to !== OrderDeliveryStatus::Delivered) {
            return;
        }

        // An online order's collection is its verified payment. Recorded when the
        // payment was confirmed; asked for again here, harmlessly, so a fact a
        // failed attempt left unwritten is repaired at the delivery boundary.
        $this->onlineCollection->handle($order, $order->payment);

        $order->items
            ->filter(fn (OrderItem $item) => $item->defersProductCost())
            ->each(fn (OrderItem $item) => $this->eligibility->markDelivered($item));
    }
}
