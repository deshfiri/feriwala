<?php

namespace App\Domain\Order\Actions;

use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Order\Enums\OrderFulfillmentStatus;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderFulfillmentStatusChange;
use App\Domain\Supplier\Actions\AdvanceSupplierFulfilmentCommitment;
use App\Models\User;
use App\Support\StatusHistory\StatusChange;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Move an order's fulfilment status by hand (§18, §20).
 *
 * A reason is required for a hold or a cancellation, the same bar every other
 * manual correction in this codebase holds itself to (see
 * {@see AdvanceSupplierFulfilmentCommitment}).
 * {@see RollUpOrderStatus} runs in the same transaction, so the order's own
 * status stays in step wherever it can.
 */
class AdvanceOrderFulfilmentStatus
{
    /** @var array<int, OrderFulfillmentStatus> */
    private const REASON_REQUIRED = [
        OrderFulfillmentStatus::OnHold,
        OrderFulfillmentStatus::Cancelled,
    ];

    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
        protected RollUpOrderStatus $rollUp,
    ) {}

    public function handle(
        Order $order,
        OrderFulfillmentStatus $to,
        ?User $actor,
        ?string $reason = null,
        OrderStatusChangeSource $source = OrderStatusChangeSource::Staff,
    ): OrderFulfillmentStatusChange {
        $reason = $reason === null ? null : trim($reason);

        if (in_array($to, self::REASON_REQUIRED, true) && ($reason === null || $reason === '')) {
            throw new InvalidArgumentException('A reason is required for this move.');
        }

        return $this->database->transaction(function () use ($order, $to, $actor, $reason, $source) {
            /** @var Order $locked */
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            /** @var OrderFulfillmentStatusChange $entry */
            $entry = $locked->moveFulfilmentTo(
                $to,
                new StatusChange(actorId: $actor?->id, reason: $reason),
                $source,
            );

            $this->rollUp->handle($locked);

            $this->audit->handle(new AuditEntry(
                action: 'order.fulfilment_status.advanced',
                actorId: $actor?->id,
                auditableType: Order::class,
                auditableId: $locked->id,
                before: ['fulfillment_status' => $entry->previous_status?->value],
                after: ['fulfillment_status' => $entry->new_status->value],
                reason: $reason,
                module: PermissionModule::Order->value,
            ));

            return $entry;
        });
    }
}
