<?php

namespace App\Integrations\Courier\Contracts;

use App\Domain\Courier\Actions\CreateShipmentFromAllocations;
use App\Domain\Courier\Enums\CourierProviderCode;
use App\Domain\Courier\Models\Shipment;
use App\Domain\Order\Enums\OrderCourierStatus;
use App\Integrations\Courier\Drivers\ManualCourierDriver;
use App\Integrations\Payment\Contracts\PaymentGateway;
use Illuminate\Http\Request;

/**
 * One courier provider (§21, D8).
 *
 * Mirrors {@see PaymentGateway}'s shape:
 * adding a provider is one class plus a `courier_providers` row, and nothing
 * in the shipment domain's own actions knows which providers exist.
 *
 * Only {@see ManualCourierDriver}
 * implements this against a real workflow in this batch (D8) -- every method
 * here is where Steadfast's or Pathao's actual API call would go once this
 * application holds credentials and documentation for either, neither of
 * which is true today. The manual driver's own implementations are
 * deliberately inert: the shipment's own columns, set by staff through
 * {@see CreateShipmentFromAllocations} and {@see
 * \App\Domain\Courier\Actions\RecordManualCourierStatusUpdate}, already are
 * the record; there is nothing further to call out to.
 */
interface CourierProvider
{
    /**
     * Register a shipment with the provider, once its row already carries
     * everything staff entered.
     *
     * A real API driver would call out here and may update the shipment with
     * whatever the provider's own response adds (its own tracking number, a
     * label URL). The manual driver has nothing to call and does nothing.
     */
    public function createShipment(Shipment $shipment): void;

    /**
     * Ask the provider to collect this shipment.
     */
    public function requestPickup(Shipment $shipment): void;

    /**
     * Ask the provider directly what has become of this shipment, when it
     * supports being asked rather than only pushing a webhook.
     */
    public function fetchTrackingStatus(Shipment $shipment): ?OrderCourierStatus;

    /**
     * Tell the provider a shipment is cancelled, before it is picked up.
     */
    public function cancelShipment(Shipment $shipment): void;

    /**
     * Whether a webhook request genuinely came from this provider.
     *
     * Must fail closed on anything unexpected, the same rule {@see
     * \App\Integrations\Payment\Contracts\PaymentGateway::verifyWebhookSignature()}
     * holds to. A provider with no webhook support returns false
     * unconditionally.
     */
    public function verifyWebhookSignature(Request $request): bool;

    /**
     * The code this driver answers for.
     */
    public function code(): CourierProviderCode;
}
