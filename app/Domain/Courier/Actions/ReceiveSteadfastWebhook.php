<?php

namespace App\Domain\Courier\Actions;

use App\Domain\Courier\Enums\CourierProviderCode;
use App\Domain\Courier\Enums\ShipmentStatusChangeSource;
use App\Domain\Courier\Models\CourierProvider;
use App\Domain\Courier\Models\CourierWebhookReceipt;
use App\Domain\Courier\Models\Shipment;
use App\Domain\Order\Models\Order;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * A verified Steadfast push, applied once.
 *
 * Authentication and shape are the controller's job; by here the payload is a
 * known array with the five documented fields. This records that the event was
 * received, finds the parcel by Banij's invoice reference, and applies the status
 * through {@see ApplySteadfastStatus} -- the same path as the poll.
 *
 * The receipt and the effect are **one transaction**: if processing fails the
 * receipt rolls back with it, so Steadfast's retry is processed rather than
 * dismissed as a duplicate. The same event arriving twice at once serialises on
 * the receipt's unique index; the loser sees a duplicate.
 *
 * The event key is a hash of the four fields that identify an event
 * (consignment, invoice, status, Steadfast's `updated_at`), because Steadfast
 * sends no event id. The body itself is never stored. `cod_amount` is ignored:
 * it is not evidence that cash was collected or settled.
 */
class ReceiveSteadfastWebhook
{
    public const ACCEPTED = 'accepted';

    public const DUPLICATE = 'duplicate';

    public const UNMATCHED = 'unmatched';

    public function __construct(
        protected DatabaseManager $database,
        protected ApplySteadfastStatus $apply,
    ) {}

    /**
     * @param  array{consignment_id: int|string, invoice: string, status: string, updated_at: string}  $payload
     * @return self::ACCEPTED|self::DUPLICATE|self::UNMATCHED
     */
    public function handle(array $payload, string $rawBody): string
    {
        $consignmentId = (string) $payload['consignment_id'];
        $eventKey = 'sf:'.hash('sha256', implode('|', [$consignmentId, $payload['invoice'], $payload['status'], $payload['updated_at']]));

        /** @var CourierProvider $provider */
        $provider = CourierProvider::query()->where('code', CourierProviderCode::Steadfast->value)->firstOrFail();

        try {
            return $this->database->transaction(function () use ($payload, $rawBody, $consignmentId, $eventKey, $provider): string {
                $receipt = CourierWebhookReceipt::query()->create([
                    'courier_provider_id' => $provider->id,
                    'event_key' => $eventKey,
                    'payload_hash' => hash('sha256', $rawBody),
                    'received_at' => now(),
                ]);

                $shipment = $this->shipmentFor($provider->id, $payload['invoice'], $consignmentId);

                if ($shipment === null) {
                    $receipt->forceFill(['outcome' => self::UNMATCHED, 'processed_at' => now()])->save();

                    return self::UNMATCHED;
                }

                // A parcel recovered by invoice alone has no Steadfast id yet:
                // the first push names it. Never overwrites one already known.
                if ($shipment->external_id === null) {
                    $shipment->forceFill(['external_id' => $consignmentId])->save();
                }

                $this->apply->handle($shipment, $payload['status'], ShipmentStatusChangeSource::Webhook);

                $receipt->forceFill(['shipment_id' => $shipment->id, 'outcome' => self::ACCEPTED, 'processed_at' => now()])->save();

                return self::ACCEPTED;
            });
        } catch (UniqueConstraintViolationException) {
            return self::DUPLICATE;
        }
    }

    /**
     * The Steadfast shipment for an invoice reference. The consignment id, when
     * the shipment already has one, must agree: a push that names a known
     * invoice with someone else's consignment is not trusted to move it.
     */
    protected function shipmentFor(int $providerId, string $invoice, string $consignmentId): ?Shipment
    {
        $orderId = Order::query()->where('reference', $invoice)->value('id');

        if ($orderId === null) {
            return null;
        }

        /** @var Shipment|null $shipment */
        $shipment = Shipment::query()
            ->where('order_id', $orderId)
            ->where('courier_provider_id', $providerId)
            ->orderByDesc('id')
            ->first();

        if ($shipment === null || ($shipment->external_id !== null && $shipment->external_id !== $consignmentId)) {
            return null;
        }

        return $shipment;
    }
}
