<?php

namespace App\Integrations\Courier\Drivers;

use App\Domain\Courier\Actions\BookSteadfastShipment;
use App\Domain\Courier\Enums\CourierProviderCode;
use App\Domain\Courier\Exceptions\UnsupportedCourierOperation;
use App\Domain\Courier\Models\Shipment;
use App\Domain\Order\Enums\OrderCourierStatus;
use App\Integrations\Courier\Contracts\CourierProvider;
use App\Integrations\Courier\Steadfast\SteadfastCredentials;
use Illuminate\Http\Request;

/**
 * Steadfast behind the shared {@see CourierProvider} contract.
 *
 * The actual booking is not here: {@see BookSteadfastShipment} calls Steadfast
 * *before* a shipment row exists (a network call must not run inside the
 * transaction that creates one), and the shared shipment action then calls
 * {@see self::createShipment()} with the row already complete -- so this does
 * nothing, like the manual driver.
 *
 * Operations Steadfast's maintained package does not demonstrate are refused
 * honestly rather than pretending: there is no cancel or pickup endpoint to
 * call, so cancelling a Steadfast parcel is done in Steadfast's merchant panel
 * and Banij learns of it from the status Steadfast then reports.
 */
class SteadfastCourierDriver implements CourierProvider
{
    public function __construct(
        protected SteadfastCredentials $credentials,
    ) {}

    public function createShipment(Shipment $shipment): void
    {
        // Booked before this row existed -- see the class docblock.
    }

    public function requestPickup(Shipment $shipment): void
    {
        throw UnsupportedCourierOperation::for(CourierProviderCode::Steadfast, 'pickup request');
    }

    public function fetchTrackingStatus(Shipment $shipment): ?OrderCourierStatus
    {
        // Polling goes through the status synchroniser, which keeps the raw
        // provider status and flags what it cannot map.
        return null;
    }

    public function cancelShipment(Shipment $shipment): void
    {
        throw UnsupportedCourierOperation::for(CourierProviderCode::Steadfast, 'cancellation');
    }

    /**
     * `Authorization: Bearer <token>` compared in full, in constant time.
     * Fails closed: no token configured, or none sent, is not a match.
     */
    public function verifyWebhookSignature(Request $request): bool
    {
        $token = $this->credentials->webhookToken();
        $sent = $request->header('Authorization');

        if ($token === null || ! is_string($sent) || $sent === '') {
            return false;
        }

        return hash_equals('Bearer '.$token, $sent);
    }

    public function code(): CourierProviderCode
    {
        return CourierProviderCode::Steadfast;
    }
}
