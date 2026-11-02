<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Courier\Actions\CreateShipmentFromAllocations;
use App\Domain\Courier\Actions\RecordManualCourierStatusUpdate;
use App\Domain\Courier\Enums\CourierProviderCode;
use App\Domain\Courier\Enums\ShipmentStatusChangeSource;
use App\Domain\Courier\Models\Shipment;
use App\Domain\Order\Enums\OrderCourierStatus;
use App\Domain\Order\Enums\OrderFulfillmentStatus;
use App\Domain\Order\Models\Order;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * What a courier API adds to a shipment, provider-neutral (D8): the provider's
 * own identifier and status, booking attempts that exist before the call, and
 * receipts that make a push idempotent. Everything is additive; the manual
 * workflow is untouched.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->staff = testPlatformStaff(PlatformRole::Admin);
    $this->order = Order::factory()->create(['fulfillment_status' => OrderFulfillmentStatus::ReadyForDispatch]);
    $this->shipment = app(CreateShipmentFromAllocations::class)->handle(
        $this->order,
        CourierProviderCode::Manual,
        Money::fromDecimal('80.00', Currency::BDT),
        [['weight' => '1.500', 'length' => '20.00', 'width' => '15.00', 'height' => '10.00']],
        $this->staff,
    );
});

function courierTrackingAttempt(Order $order, Shipment $shipment, string $status, ?int $shipmentId = null): array
{
    return [
        'public_id' => (string) Str::ulid(),
        'order_id' => $order->id,
        'courier_provider_id' => $shipment->courier_provider_id,
        'shipment_id' => $shipmentId,
        'status' => $status,
        'idempotency_key' => Str::random(40),
        'started_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ];
}

describe('a status that did not come from staff', function () {
    it('records its source on the history and the tracking event, with nobody as the actor', function () {
        app(RecordManualCourierStatusUpdate::class)->handle(
            $this->shipment->refresh(),
            OrderCourierStatus::PickupRequested,
            null,
            source: ShipmentStatusChangeSource::Webhook,
        );

        $this->shipment->refresh();

        expect($this->shipment->status)->toBe(OrderCourierStatus::PickupRequested)
            ->and($this->shipment->statusHistory()->reorder('id', 'desc')->first()->source)->toBe(ShipmentStatusChangeSource::Webhook)
            ->and($this->shipment->trackingEvents()->reorder('id', 'desc')->first()->source)->toBe('webhook')
            ->and($this->order->refresh()->courier_status)->toBe(OrderCourierStatus::PickupRequested);
    });

    it('is still held to the status map', function () {
        expect(fn () => app(RecordManualCourierStatusUpdate::class)->handle(
            $this->shipment->refresh(),
            OrderCourierStatus::Delivered,
            null,
            source: ShipmentStatusChangeSource::Webhook,
        ))->toThrow(Exception::class, 'cannot move');

        expect($this->shipment->refresh()->status)->toBe(OrderCourierStatus::Assigned);
    });
});

describe('what the provider reports', function () {
    it('keeps the provider\'s charge apart from the charge the shipment froze', function () {
        $this->shipment->forceFill(['provider_charge' => '65.00'])->save();

        expect($this->shipment->refresh()->provider_charge)->toBe('65.00')
            ->and($this->shipment->delivery_charge->toDecimal())->toBe('80.00');

        expect(fn () => $this->shipment->forceFill(['delivery_charge' => '1.00'])->save())
            ->toThrow(InvalidArgumentException::class);
    });

    it('lets one provider identifier name only one shipment', function () {
        $this->shipment->forceFill(['external_id' => 'C-1001'])->save();

        $other = app(CreateShipmentFromAllocations::class)->handle(
            Order::factory()->create(['fulfillment_status' => OrderFulfillmentStatus::ReadyForDispatch]),
            CourierProviderCode::Manual,
            Money::fromDecimal('80.00', Currency::BDT),
            [['weight' => '1.000']],
            $this->staff,
        );

        expect(fn () => DB::transaction(fn () => $other->forceFill(['external_id' => 'C-1001'])->save()))
            ->toThrow(QueryException::class);

        // A shipment with no identifier yet is not in conflict with another.
        expect($other->refresh()->external_id)->toBeNull();
    });
});

describe('booking attempts', function () {
    it('allows one live attempt per order, however it is retried', function () {
        DB::table('courier_booking_attempts')->insert(courierTrackingAttempt($this->order, $this->shipment, 'pending'));

        foreach (['pending', 'uncertain'] as $status) {
            expect(fn () => DB::transaction(fn () => DB::table('courier_booking_attempts')
                ->insert(courierTrackingAttempt($this->order, $this->shipment, $status))))
                ->toThrow(QueryException::class);
        }
    });

    it('lets a failed attempt be followed by another', function () {
        DB::table('courier_booking_attempts')->insert(courierTrackingAttempt($this->order, $this->shipment, 'failed'));
        DB::table('courier_booking_attempts')->insert(courierTrackingAttempt($this->order, $this->shipment, 'pending'));

        expect(DB::table('courier_booking_attempts')->where('order_id', $this->order->id)->count())->toBe(2);
    });

    it('refuses a booked attempt that has no shipment', function () {
        expect(fn () => DB::transaction(fn () => DB::table('courier_booking_attempts')
            ->insert(courierTrackingAttempt($this->order, $this->shipment, 'booked'))))
            ->toThrow(QueryException::class);
    });

    it('refuses a status it does not know', function () {
        expect(fn () => DB::transaction(fn () => DB::table('courier_booking_attempts')
            ->insert(courierTrackingAttempt($this->order, $this->shipment, 'maybe'))))
            ->toThrow(QueryException::class);
    });
});

describe('webhook receipts', function () {
    it('lets one provider event be received once', function () {
        $receipt = [
            'courier_provider_id' => $this->shipment->courier_provider_id,
            'event_key' => 'evt-1',
            'payload_hash' => hash('sha256', 'body'),
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('courier_webhook_receipts')->insert($receipt);

        expect(fn () => DB::transaction(fn () => DB::table('courier_webhook_receipts')->insert($receipt)))
            ->toThrow(QueryException::class);

        expect(DB::table('courier_webhook_receipts')->count())->toBe(1);
    });
});
