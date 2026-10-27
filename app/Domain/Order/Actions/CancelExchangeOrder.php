<?php

namespace App\Domain\Order\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Inventory\Enums\StockReservationStatus;
use App\Domain\Inventory\Exceptions\InventoryRefused;
use App\Domain\Inventory\StockReservations;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderReturn;
use App\Models\User;
use App\Support\StatusHistory\StatusChange;

/**
 * Call an exchange's replacement order off when the case that asked for it ends
 * without the goods coming back (D35): the request was refused or withdrawn, or
 * inspection refused the goods.
 *
 * Gives back any stock the replacement held, and moves the order to cancelled —
 * if the order is somewhere cancelling is a legal move. A replacement that has
 * already left the warehouse cannot be un-sent by a status: it is left exactly
 * as it is, and the case's audit trail says so, so somebody deals with the
 * parcel rather than the system pretending it never went.
 *
 * Called inside the caller's transaction, with the case locked. Once: an order
 * already cancelled is returned as it stands.
 */
class CancelExchangeOrder
{
    public function __construct(
        protected StockReservations $reservations,
        protected RecordAuditLog $audit,
    ) {}

    /**
     * @return bool whether the replacement order is now cancelled
     */
    public function handle(OrderReturn $case, string $reason, ?User $actor = null, OrderStatusChangeSource $source = OrderStatusChangeSource::Staff): bool
    {
        if ($case->exchange_order_id === null) {
            return false;
        }

        /** @var Order $order */
        $order = Order::query()->lockForUpdate()->findOrFail($case->exchange_order_id);

        if ($order->status === OrderStatus::Cancelled) {
            return true;
        }

        if (! $order->canTransitionTo(OrderStatus::Cancelled)) {
            // On its way already: a status cannot recall a parcel.
            $this->audit->handle(new AuditEntry(
                action: 'exchange_order.not_cancellable',
                actorId: $actor?->id,
                auditableType: Order::class,
                auditableId: $order->id,
                after: ['reference' => $order->reference, 'status' => $order->status->value, 'case' => $case->reference],
                reason: $reason,
                accountId: $order->business_account_id,
                module: 'return',
            ));

            return false;
        }

        foreach ($order->items()->with('stockReservation')->get() as $item) {
            $reservation = $item->stockReservation;

            if ($reservation === null || $reservation->status !== StockReservationStatus::Active) {
                continue;
            }

            try {
                $this->reservations->release($reservation, $reason);
            } catch (InventoryRefused) {
                // Already ended another way; nothing left to give back.
            }
        }

        $order->forceFill(['cancelled_at' => now(), 'cancellation_reason' => $reason]);

        $order->moveTo(
            OrderStatus::Cancelled,
            new StatusChange(actorId: $actor?->id, reason: $reason, publicNote: 'orders.notes.exchange_cancelled'),
            $source,
        );

        return true;
    }
}
