<?php

namespace App\Domain\Courier\Actions;

use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Courier\Enums\ShipmentStatusChangeSource;
use App\Domain\Courier\Models\Shipment;
use App\Domain\Order\Actions\AdvanceOrderCourierStatus;
use App\Domain\Order\Actions\AdvanceOrderDeliveryStatus;
use App\Domain\Order\Enums\OrderCourierStatus;
use App\Domain\Order\Enums\OrderDeliveryStatus;
use App\Domain\Order\Models\Order;
use App\Models\User;
use App\Support\StatusHistory\StatusChange;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Staff recording what a courier has told them by phone or by hand, since
 * D8's manual driver has no webhook to tell it anything itself (Advanced
 * Order Management batch, Commit 5).
 *
 * The one place a shipment's status moves after it is created — also what a
 * real provider's driver would call from a webhook once one exists, with
 * `ShipmentStatusChangeSource::Webhook` in place of `::Staff`, so this is not
 * a dead end the day a real integration arrives.
 *
 * Mirrors the shipment's own move onto the order's {@see OrderCourierStatus}
 * unconditionally (a shipment's status *is* that axis — see the `shipments`
 * migration), and onto {@see OrderDeliveryStatus} only where {@see
 * mapToDeliveryStatus()} has an unambiguous equivalent. `ReturnedToOrigin`
 * has none: it means the courier physically has the parcel back at their own
 * depot, which is not the same claim as the Return domain's own
 * `ReturnRequested`/`Returned` (a customer sending goods back after
 * delivery), so it is left for staff to decide by hand what the order's
 * delivery status should do next.
 */
class RecordManualCourierStatusUpdate
{
    /** @var array<int, OrderCourierStatus> */
    private const REASON_REQUIRED = [
        OrderCourierStatus::Cancelled,
        OrderCourierStatus::FailedDelivery,
    ];

    public function __construct(
        protected DatabaseManager $database,
        protected AdvanceOrderCourierStatus $advanceCourier,
        protected AdvanceOrderDeliveryStatus $advanceDelivery,
        protected RecordAuditLog $audit,
    ) {}

    public function handle(
        Shipment $shipment,
        OrderCourierStatus $to,
        ?User $actor,
        ?string $reason = null,
    ): Shipment {
        $reason = $reason === null ? null : trim($reason);

        if (in_array($to, self::REASON_REQUIRED, true) && ($reason === null || $reason === '')) {
            throw new InvalidArgumentException('A reason is required for this move.');
        }

        return $this->database->transaction(function () use ($shipment, $to, $actor, $reason) {
            /** @var Shipment $locked */
            $locked = Shipment::query()->lockForUpdate()->findOrFail($shipment->id);

            if ($to === OrderCourierStatus::PickupRequested) {
                $locked->forceFill(['pickup_requested_at' => now()]);
            }

            if ($to === OrderCourierStatus::Cancelled) {
                $locked->forceFill([
                    'cancelled_by' => $actor?->id,
                    'cancellation_reason' => $reason,
                    'cancelled_at' => now(),
                ]);
            }

            $locked->transitionWithHistory(
                $to,
                new StatusChange(actorId: $actor?->id, reason: $reason),
                ['source' => ShipmentStatusChangeSource::Staff],
            );

            $locked->trackingEvents()->create([
                'event_code' => $to->value,
                'description' => $reason,
                'occurred_at' => now(),
                'source' => 'manual',
            ]);

            /** @var Order $order */
            $order = Order::query()->lockForUpdate()->findOrFail($locked->order_id);

            $this->advanceCourier->handle($order, $to, $actor, $reason);

            $deliveryTarget = $this->mapToDeliveryStatus($to);

            if ($deliveryTarget !== null) {
                $order->refresh();

                // A courier handover straight to Delivered needs an
                // intermediate hop when the order's own delivery axis is
                // still at InTransit -- that axis has no direct
                // InTransit -> Delivered move (OrderDeliveryStatus has its
                // own, coarser OutForDelivery step courier tracking has no
                // equivalent for).
                if ($deliveryTarget === OrderDeliveryStatus::Delivered
                    && $order->delivery_status === OrderDeliveryStatus::InTransit) {
                    $this->advanceDelivery->handle($order, OrderDeliveryStatus::OutForDelivery, $actor);
                    $order->refresh();
                }

                if ($order->canTransitionTo($deliveryTarget, 'delivery_status')) {
                    $this->advanceDelivery->handle($order, $deliveryTarget, $actor, $reason);
                }
            }

            $this->audit->handle(new AuditEntry(
                action: 'shipment.status_advanced',
                actorId: $actor?->id,
                auditableType: Shipment::class,
                auditableId: $locked->id,
                after: ['status' => $to->value],
                reason: $reason,
                module: PermissionModule::Courier->value,
            ));

            return $locked->refresh();
        });
    }

    /**
     * Where a shipment-level move lands on the order's own, coarser delivery
     * axis -- null where there is none (see the class docblock).
     */
    protected function mapToDeliveryStatus(OrderCourierStatus $to): ?OrderDeliveryStatus
    {
        return match ($to) {
            OrderCourierStatus::PickedUp => OrderDeliveryStatus::Shipped,
            OrderCourierStatus::InTransit => OrderDeliveryStatus::InTransit,
            OrderCourierStatus::Delivered => OrderDeliveryStatus::Delivered,
            OrderCourierStatus::FailedDelivery => OrderDeliveryStatus::FailedDelivery,
            OrderCourierStatus::Cancelled => OrderDeliveryStatus::Cancelled,
            default => null,
        };
    }
}
