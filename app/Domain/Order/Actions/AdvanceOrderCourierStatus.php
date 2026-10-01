<?php

namespace App\Domain\Order\Actions;

use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Courier\Models\Shipment;
use App\Domain\Order\Enums\OrderCourierStatus;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderCourierStatusChange;
use App\Models\User;
use App\Support\StatusHistory\StatusChange;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Move an order's courier status by hand (§18, §21).
 *
 * Manual today — the courier domain (Commit 5) drives this the same way once
 * a {@see Shipment} exists, through the same
 * method, never by assigning the attribute directly.
 */
class AdvanceOrderCourierStatus
{
    /** @var array<int, OrderCourierStatus> */
    private const REASON_REQUIRED = [
        OrderCourierStatus::Cancelled,
        OrderCourierStatus::FailedDelivery,
    ];

    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    public function handle(Order $order, OrderCourierStatus $to, User $actor, ?string $reason = null): OrderCourierStatusChange
    {
        $reason = $reason === null ? null : trim($reason);

        if (in_array($to, self::REASON_REQUIRED, true) && ($reason === null || $reason === '')) {
            throw new InvalidArgumentException('A reason is required for this move.');
        }

        return $this->database->transaction(function () use ($order, $to, $actor, $reason) {
            /** @var Order $locked */
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            /** @var OrderCourierStatusChange $entry */
            $entry = $locked->moveCourierTo(
                $to,
                new StatusChange(actorId: $actor->id, reason: $reason),
                OrderStatusChangeSource::Staff,
            );

            $this->audit->handle(new AuditEntry(
                action: 'order.courier_status.advanced',
                actorId: $actor->id,
                auditableType: Order::class,
                auditableId: $locked->id,
                before: ['courier_status' => $entry->previous_status?->value],
                after: ['courier_status' => $entry->new_status->value],
                reason: $reason,
                module: PermissionModule::Order->value,
            ));

            return $entry;
        });
    }
}
