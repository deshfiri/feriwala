<?php

namespace App\Domain\Order\Actions;

use App\Domain\Order\Enums\OrderDeliveryStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Supplier\Actions\CancelSupplierPayable;
use App\Domain\Supplier\Actions\EvaluateSupplierPayableEligibility;
use App\Domain\Supplier\Actions\ReverseSupplierPayable;
use App\Domain\Supplier\Models\SupplierPayable;

/**
 * What a move on the order's delivery axis means for its Supplier payables
 * (Advanced Order Management batch, Commit 4 — closes P13-26).
 *
 * `RecordSupplierPayableDelivery` was always a stand-in for a real fulfilment
 * handover event (its own docblock says so): "when fulfilment lands, its
 * handover event calls this same action instead of a person." Reaching
 * {@see OrderDeliveryStatus::Delivered} is that event — every Supplier
 * payable on the order is marked delivered the same way, automatically,
 * through the same idempotent {@see EvaluateSupplierPayableEligibility}
 * every other caller already uses. Nothing here marks an order Delivered by
 * itself; that is `AdvanceOrderDeliveryStatus`'s decision, made before this
 * runs, in the same transaction.
 *
 * {@see OrderDeliveryStatus::FailedDelivery}, `::Cancelled` and `::Returned`
 * all reuse the existing {@see CancelSupplierPayable::handle()} — it already
 * cancels every still-`Pending` payable on the order and is a no-op for one
 * already `Eligible`/`Settled`/reversed, exactly the "only `Pending` moves"
 * rule the order-cancellation path already relies on.
 *
 * **Reversing an already-`Eligible`/`Settled` payable for a return is
 * deliberately left to the existing Return domain**
 * ({@see ReceiveReturnedItems} →
 * {@see ReverseSupplierPayable}), which already
 * reverses per `OrderReturnItem` at the exact quantity received back. This
 * coarser delivery-status axis has no return-item-level quantity breakdown
 * to reverse against, so duplicating that logic here would either guess at a
 * quantity or reverse the whole payable regardless of how much actually came
 * back — the Return domain already does this correctly and is the one path
 * for it.
 */
class SynchronizeSupplierPayablesWithDelivery
{
    public function __construct(
        protected EvaluateSupplierPayableEligibility $eligibility,
        protected CancelSupplierPayable $cancelPayables,
    ) {}

    public function handle(Order $order, OrderDeliveryStatus $to, ?string $reason): void
    {
        match ($to) {
            OrderDeliveryStatus::Delivered => $this->markDelivered($order),
            OrderDeliveryStatus::FailedDelivery,
            OrderDeliveryStatus::Cancelled,
            OrderDeliveryStatus::Returned => $this->cancelPayables->handle($order, $reason ?? 'Delivery did not complete.'),
            default => null,
        };
    }

    protected function markDelivered(Order $order): void
    {
        SupplierPayable::query()
            ->where('order_id', $order->id)
            ->get()
            ->each(fn (SupplierPayable $payable) => $this->eligibility->markDelivered($payable));
    }
}
