<?php

namespace App\Domain\Courier\Actions;

use App\Domain\Courier\Data\SteadfastStatusApplied;
use App\Domain\Courier\Enums\ShipmentStatusChangeSource;
use App\Domain\Courier\Models\Shipment;
use App\Domain\Order\Models\Order;
use App\Integrations\Courier\Steadfast\SteadfastClient;
use App\Integrations\Courier\Steadfast\SteadfastException;

/**
 * Asks Steadfast what has become of one parcel and applies the answer through
 * {@see ApplySteadfastStatus} -- the scheduled poll and staff "Check courier
 * status now" are this same call.
 *
 * Looks the parcel up by Banij's own invoice reference, so it works whether or
 * not Steadfast's consignment id is known. A failed lookup is remembered on the
 * shipment as a safe kind (never Steadfast's words) and is what stretches the
 * poller's wait before it asks again. A parcel Steadfast says it has never
 * heard of is flagged for review: it was booked, so that is not normal.
 */
class SynchroniseSteadfastShipment
{
    public function __construct(
        protected SteadfastClient $client,
        protected ApplySteadfastStatus $apply,
    ) {}

    /**
     * @return SteadfastStatusApplied|null null when Steadfast does not know the parcel
     *
     * @throws SteadfastException after recording it on the shipment
     */
    public function handle(Shipment $shipment): ?SteadfastStatusApplied
    {
        /** @var Order $order */
        $order = Order::query()->findOrFail($shipment->order_id);

        try {
            $lookup = $this->client->statusByInvoice($order->reference);
        } catch (SteadfastException $exception) {
            $shipment->forceFill([
                'provider_synced_at' => now(),
                'provider_sync_error' => $exception->kind->value,
            ])->save();

            throw $exception;
        }

        if (! $lookup->found || $lookup->status === null) {
            $shipment->forceFill([
                'provider_synced_at' => now(),
                'provider_sync_error' => 'not_found',
                'needs_review' => true,
            ])->save();

            return null;
        }

        return $this->apply->handle($shipment, $lookup->status, ShipmentStatusChangeSource::System);
    }
}
