<?php

namespace App\Domain\Order\Actions;

use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Order\Enums\OrderDeliveryStatus;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderDeliveryStatusChange;
use App\Models\User;
use App\Support\StatusHistory\StatusChange;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Move an order's delivery status by hand (§18, §21).
 *
 * A reason is required for a hold, a cancellation, a failed-delivery or a
 * return — every move that stops the normal path and needs explaining.
 * {@see RollUpOrderStatus} runs in the same transaction: reaching `Delivered`
 * here is what moves the order's own status to `Delivered`.
 *
 * {@see SynchronizeSupplierPayablesWithDelivery} also runs in the same
 * transaction: reaching `Delivered` is what marks every Supplier payable on
 * the order delivered (closes P13-26), and reaching `FailedDelivery`,
 * `Cancelled` or `Returned` cancels any that are still `Pending`.
 *
 * {@see SynchronizeDeliverySuccessFeesWithDelivery} and {@see
 * SynchronizeOrderProceedsWithDelivery} run here too (D-new): reaching
 * `Delivered` is what charges the Delivery Success Fee against every
 * Supplier payable on the order, and what records the "delivered" half of a
 * Non-Conditional order's reseller-earning eligibility — never, by itself,
 * what credits a reseller's wallet.
 */
class AdvanceOrderDeliveryStatus
{
    /** @var array<int, OrderDeliveryStatus> */
    private const REASON_REQUIRED = [
        OrderDeliveryStatus::OnHold,
        OrderDeliveryStatus::Cancelled,
        OrderDeliveryStatus::FailedDelivery,
        OrderDeliveryStatus::ReturnRequested,
    ];

    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
        protected RollUpOrderStatus $rollUp,
        protected SynchronizeSupplierPayablesWithDelivery $supplierPayables,
        protected SynchronizeDeliverySuccessFeesWithDelivery $deliverySuccessFees,
        protected SynchronizeOrderProceedsWithDelivery $orderProceeds,
    ) {}

    public function handle(
        Order $order,
        OrderDeliveryStatus $to,
        ?User $actor,
        ?string $reason = null,
        OrderStatusChangeSource $source = OrderStatusChangeSource::Staff,
    ): OrderDeliveryStatusChange {
        $reason = $reason === null ? null : trim($reason);

        if (in_array($to, self::REASON_REQUIRED, true) && ($reason === null || $reason === '')) {
            throw new InvalidArgumentException('A reason is required for this move.');
        }

        return $this->database->transaction(function () use ($order, $to, $actor, $reason, $source) {
            /** @var Order $locked */
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            /** @var OrderDeliveryStatusChange $entry */
            $entry = $locked->moveDeliveryTo(
                $to,
                new StatusChange(actorId: $actor?->id, reason: $reason),
                $source,
            );

            $this->rollUp->handle($locked);

            $this->supplierPayables->handle($locked, $to, $reason);
            $this->deliverySuccessFees->handle($locked, $to);
            $this->orderProceeds->handle($locked, $to);

            $this->audit->handle(new AuditEntry(
                action: 'order.delivery_status.advanced',
                actorId: $actor?->id,
                auditableType: Order::class,
                auditableId: $locked->id,
                before: ['delivery_status' => $entry->previous_status?->value],
                after: ['delivery_status' => $entry->new_status->value],
                reason: $reason,
                module: PermissionModule::Order->value,
            ));

            return $entry;
        });
    }
}
