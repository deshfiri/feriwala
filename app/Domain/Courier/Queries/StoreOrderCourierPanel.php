<?php

namespace App\Domain\Courier\Queries;

use App\Domain\Courier\Enums\CourierBookingStatus;
use App\Domain\Courier\Enums\CourierProviderCode;
use App\Domain\Courier\Models\CourierBookingAttempt;
use App\Domain\Courier\Models\Shipment;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Enums\StoreOrderStep;
use App\Domain\Order\Models\Order;
use App\Integrations\Courier\CourierManager;
use App\Integrations\Courier\Steadfast\SteadfastFailureKind;
use App\Integrations\Courier\Steadfast\SteadfastStatus;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Support\Facades\Gate;

/**
 * What a Store Order's page shows about its Steadfast booking.
 *
 * **Two payloads, deliberately different.** Staff get the booking state, the
 * consignment and tracking references, Steadfast's raw status beside Banij's own,
 * the charge Steadfast reported, when it was last asked, and what they may do
 * about it. A Partner gets the courier's name, the tracking reference, Banij's
 * customer-safe status, when it changed and a timeline of Banij's own statuses --
 * never a raw courier value, an error, a review flag or a credential.
 *
 * No tracking link is built: Steadfast's tracking-page address is not part of
 * the contract Banij has verified.
 */
class StoreOrderCourierPanel
{
    public function __construct(
        protected CourierManager $couriers,
    ) {}

    /**
     * @return array<string, mixed>|null null when the order is not one a courier can be booked for
     */
    public function forStaff(Order $order, User $actor): ?array
    {
        if ($order->source !== OrderSource::Website) {
            return null;
        }

        $shipment = $this->shipment($order);
        $attempt = CourierBookingAttempt::query()->where('order_id', $order->id)->orderByDesc('id')->first();

        $manage = Gate::forUser($actor)->allows('manageShipments', Shipment::class);
        $live = $attempt !== null && $attempt->status->isLive();

        return [
            'enabled' => $this->couriers->isAvailable(CourierProviderCode::Steadfast),
            'booking' => $attempt === null ? null : [
                'id' => $attempt->public_id,
                'status' => $attempt->status->value,
                'status_label' => $attempt->status->label(),
                'message' => $attempt->status === CourierBookingStatus::Booked ? null : $attempt->failure_message,
                'at' => ($attempt->completed_at ?? $attempt->started_at)->toIso8601String(),
            ],
            'shipment' => $shipment === null ? null : $this->staffShipment($shipment, $manage),
            'can' => [
                'book' => $manage
                    && $this->couriers->isAvailable(CourierProviderCode::Steadfast)
                    && ! $live
                    && $shipment === null
                    && StoreOrderStep::hasDispatched($order->status),
                'reconcile' => $manage && $attempt?->status === CourierBookingStatus::Uncertain,
                'mark_not_booked' => $manage && $attempt?->status === CourierBookingStatus::Uncertain,
            ],
            'urls' => [
                'book' => route('admin.orders.steadfast-booking.store', $order->public_id),
                'reconcile' => $attempt === null ? null : route('admin.courier.booking-attempts.reconcile', $attempt->public_id),
                'not_booked' => $attempt === null ? null : route('admin.courier.booking-attempts.not-booked', $attempt->public_id),
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null null until a courier has been booked
     */
    public function forPartner(Order $order): ?array
    {
        $shipment = $this->shipment($order);

        if ($shipment === null) {
            return null;
        }

        $shipment->loadMissing('courierProvider', 'statusHistory');

        $history = $shipment->statusHistory;
        $updatedAt = $history->isNotEmpty() ? $history->last()->changed_at : $shipment->updated_at;

        return [
            'name' => $shipment->courierProvider->name,
            'tracking_number' => $shipment->tracking_number,
            'status' => $shipment->status->value,
            'status_label' => $shipment->status->label(),
            'status_tone' => $shipment->status->tone(),
            'updated_at' => $updatedAt->toIso8601String(),
            'events' => $shipment->statusHistory->map(fn ($change) => [
                'label' => $change->new_status->label(),
                'at' => $change->changed_at->toIso8601String(),
            ])->values()->all(),
        ];
    }

    protected function shipment(Order $order): ?Shipment
    {
        /** @var Shipment|null $shipment */
        $shipment = Shipment::query()
            ->where('order_id', $order->id)
            ->whereHas('courierProvider', fn ($query) => $query->where('code', CourierProviderCode::Steadfast->value))
            ->orderByDesc('id')
            ->first();

        return $shipment;
    }

    /**
     * @return array<string, mixed>
     */
    protected function staffShipment(Shipment $shipment, bool $manage): array
    {
        $raw = $shipment->provider_status;
        $known = SteadfastStatus::fromRaw($raw);

        return [
            'id' => $shipment->public_id,
            'consignment_id' => $shipment->external_id,
            'tracking_number' => $shipment->tracking_number,
            'raw_status' => $raw,
            // Steadfast's own word, translated; null when Banij does not recognise it.
            'raw_status_label' => $known === null ? null : __("steadfast.raw.{$known->value}"),
            'status' => $shipment->status->value,
            'status_label' => $shipment->status->label(),
            'status_tone' => $shipment->status->tone(),
            'charge' => $shipment->provider_charge === null ? null : Money::fromDecimal($shipment->provider_charge, Currency::BDT)->jsonSerialize(),
            'last_checked_at' => $shipment->provider_synced_at?->toIso8601String(),
            'sync_error' => $shipment->provider_sync_error === null ? null : $this->syncError($shipment->provider_sync_error),
            'needs_review' => (bool) $shipment->needs_review,
            'can_sync' => $manage
                && ! $shipment->status->isTerminal()
                && ($known === null || ! $known->isProviderFinal()),
            'sync_url' => route('admin.shipments.steadfast-sync', $shipment->public_id),
        ];
    }

    protected function syncError(string $code): string
    {
        $kind = SteadfastFailureKind::tryFrom($code);

        return $kind?->message() ?? __("steadfast.sync_error.{$code}");
    }
}
