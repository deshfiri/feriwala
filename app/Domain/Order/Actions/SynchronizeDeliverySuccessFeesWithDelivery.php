<?php

namespace App\Domain\Order\Actions;

use App\Domain\Order\Enums\OrderDeliveryStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Supplier\Actions\ApplyDeliverySuccessFee;
use App\Domain\Supplier\Models\SupplierPayable;

/**
 * What a move on the order's delivery axis means for the Delivery Success
 * Fee (D-new).
 *
 * Reaching {@see OrderDeliveryStatus::Delivered} charges the fee against
 * every Supplier payable on the order — identically for both Account Types,
 * and a no-op for a line with no Supplier. Mirrors {@see
 * SynchronizeSupplierPayablesWithDelivery}'s own shape exactly: nothing here
 * marks an order Delivered by itself, that is {@see
 * AdvanceOrderDeliveryStatus}'s decision, made before this runs, in the same
 * transaction.
 */
class SynchronizeDeliverySuccessFeesWithDelivery
{
    public function __construct(
        protected ApplyDeliverySuccessFee $fees,
    ) {}

    public function handle(Order $order, OrderDeliveryStatus $to): void
    {
        if ($to !== OrderDeliveryStatus::Delivered) {
            return;
        }

        SupplierPayable::query()
            ->where('order_id', $order->id)
            ->get()
            ->each(fn (SupplierPayable $payable) => $this->fees->handle($payable));
    }
}
