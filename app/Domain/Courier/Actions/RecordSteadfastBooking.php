<?php

namespace App\Domain\Courier\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Courier\Enums\CourierBookingStatus;
use App\Domain\Courier\Enums\CourierProviderCode;
use App\Domain\Courier\Models\CourierBookingAttempt;
use App\Domain\Courier\Models\Shipment;
use App\Domain\Order\Models\Order;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Database\DatabaseManager;

/**
 * Turns a confirmed Steadfast consignment into Banij's own records: a shipment,
 * created through the same {@see CreateShipmentFromAllocations} every provider
 * uses, and an attempt marked booked.
 *
 * Shared by the booking itself and by reconciliation, so a parcel Steadfast
 * holds ends up in one state however Banij learned of it. Idempotent: an
 * attempt that is already booked returns its shipment untouched.
 *
 * Does **not** dispatch the order, move stock, record COD, credit profit or mark
 * anything picked up or delivered -- booking is "a courier has been asked".
 */
class RecordSteadfastBooking
{
    public function __construct(
        protected DatabaseManager $database,
        protected CreateShipmentFromAllocations $createShipment,
        protected RecordAuditLog $audit,
    ) {}

    /**
     * @param  string|null  $consignmentId  null when only the invoice lookup confirmed the parcel
     */
    public function handle(
        CourierBookingAttempt $attempt,
        ?User $actor,
        ?string $consignmentId,
        ?string $trackingCode,
        ?string $rawStatus,
    ): Shipment {
        return $this->database->transaction(function () use ($attempt, $actor, $consignmentId, $trackingCode, $rawStatus): Shipment {
            /** @var CourierBookingAttempt $locked */
            $locked = CourierBookingAttempt::query()->lockForUpdate()->findOrFail($attempt->id);

            if ($locked->status === CourierBookingStatus::Booked && $locked->shipment_id !== null) {
                return Shipment::query()->findOrFail($locked->shipment_id);
            }

            /** @var Order $order */
            $order = Order::query()->lockForUpdate()->findOrFail($locked->order_id);

            $cod = Money::fromDecimal($locked->request_snapshot['cod_amount'] ?? '0', Currency::BDT);

            $shipment = $this->createShipment->handle(
                $order,
                CourierProviderCode::Steadfast,
                $order->delivery,
                [[]],
                $actor,
                $trackingCode,
                $cod,
            );

            $shipment->forceFill([
                'external_id' => $consignmentId,
                'provider_status' => $rawStatus,
                'provider_status_at' => now(),
                'provider_synced_at' => now(),
                // Confirmed by invoice alone: Steadfast's identifier and tracking
                // code are not known here, so a person is asked to look.
                'needs_review' => $consignmentId === null,
            ])->save();

            $locked->forceFill([
                'status' => CourierBookingStatus::Booked,
                'shipment_id' => $shipment->id,
                'failure_code' => null,
                'failure_message' => null,
                'completed_at' => now(),
            ])->save();

            $this->audit->handle(new AuditEntry(
                action: 'courier.booking_confirmed',
                actorId: $actor?->id,
                auditableType: CourierBookingAttempt::class,
                auditableId: $locked->id,
                // The order's reference and whether Steadfast's id is known. Not the
                // customer, the address, or anything Steadfast said.
                after: ['order' => $order->reference, 'provider' => 'steadfast', 'consignment_known' => $consignmentId !== null],
                module: 'courier',
            ));

            return $shipment;
        });
    }
}
