<?php

namespace App\Integrations\Courier\Drivers;

use App\Domain\Courier\Actions\CreateShipmentFromAllocations;
use App\Domain\Courier\Enums\CourierProviderCode;
use App\Domain\Courier\Models\Shipment;
use App\Domain\Order\Enums\OrderCourierStatus;
use App\Integrations\Courier\Contracts\CourierProvider;
use Illuminate\Http\Request;

/**
 * The only courier driver this batch implements end-to-end (D8).
 *
 * There is no outbound call anywhere in this class -- a human is the
 * integration. Staff enter the tracking number and charge themselves through
 * {@see CreateShipmentFromAllocations}, and
 * advance the shipment's status themselves through {@see
 * \App\Domain\Courier\Actions\RecordManualCourierStatusUpdate}; by the time
 * either action calls this driver, the shipment row already holds everything
 * it needs. This class exists so those actions depend on {@see
 * CourierProvider}, not on "manual" being special-cased, which is what makes
 * adding Steadfast or Pathao later a new driver rather than a rewrite.
 */
class ManualCourierDriver implements CourierProvider
{
    public function createShipment(Shipment $shipment): void
    {
        // Nothing to call out to -- see the class docblock.
    }

    public function requestPickup(Shipment $shipment): void
    {
        // Staff themselves are the pickup request.
    }

    public function fetchTrackingStatus(Shipment $shipment): ?OrderCourierStatus
    {
        // No provider to ask -- the shipment's own status is the only record.
        return null;
    }

    public function cancelShipment(Shipment $shipment): void
    {
        // Nothing to call out to -- see the class docblock.
    }

    public function verifyWebhookSignature(Request $request): bool
    {
        // Manual sends no webhook, so nothing can claim to be one.
        return false;
    }

    public function code(): CourierProviderCode
    {
        return CourierProviderCode::Manual;
    }
}
