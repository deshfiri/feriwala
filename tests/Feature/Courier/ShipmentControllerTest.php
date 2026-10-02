<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Courier\Models\Shipment;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
 * The staff-facing shipment screens and their route-level authorization
 * (Advanced Order Management batch, Commit 5; §21).
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->manager = testPlatformStaff(PlatformRole::CourierManager);
    $this->order = Order::factory()->create(['status' => OrderStatus::Shipped]);
});

it('creates a shipment through the admin screen and redirects to it', function () {
    $response = $this->actingAs($this->manager)->post(
        route('admin.orders.shipments.store', $this->order->public_id),
        [
            'provider' => 'manual',
            'tracking_number' => 'TRK-99',
            'delivery_charge' => '80.00',
            'packages' => [['weight' => '1.5']],
        ],
    );

    $shipment = Shipment::query()->sole();

    $response->assertRedirect(route('admin.shipments.show', $shipment->public_id));
    expect($shipment->tracking_number)->toBe('TRK-99');
});

it('refuses a shipment through a disabled provider with a validation error', function () {
    $this->actingAs($this->manager)->post(
        route('admin.orders.shipments.store', $this->order->public_id),
        [
            'provider' => 'steadfast',
            'delivery_charge' => '80.00',
            'packages' => [['weight' => '1.5']],
        ],
    )->assertInvalid(['provider']);

    expect(Shipment::query()->count())->toBe(0);
});

it('refuses shipment creation to a staff member without courier permissions', function () {
    $viewer = testPlatformStaff(PlatformRole::OrderManager);

    $this->actingAs($viewer)->post(
        route('admin.orders.shipments.store', $this->order->public_id),
        [
            'provider' => 'manual',
            'delivery_charge' => '80.00',
            'packages' => [['weight' => '1.5']],
        ],
    )->assertForbidden();
});

it('shows the shipments index and a single shipment', function () {
    $this->actingAs($this->manager)->post(
        route('admin.orders.shipments.store', $this->order->public_id),
        [
            'provider' => 'manual',
            'delivery_charge' => '80.00',
            'packages' => [['weight' => '1.5']],
        ],
    );

    $shipment = Shipment::query()->sole();

    $this->actingAs($this->manager)
        ->get(route('admin.shipments.index'))
        ->assertInertia(fn ($page) => $page
            ->component('admin/shipments/index')
            ->where('shipments.data.0.id', $shipment->public_id));

    $this->actingAs($this->manager)
        ->get(route('admin.shipments.show', $shipment->public_id))
        ->assertInertia(fn ($page) => $page
            ->component('admin/shipments/show')
            ->where('shipment.id', $shipment->public_id)
            ->where('shipment.status', 'assigned'));
});

it('advances a shipment status through the admin screen', function () {
    $this->actingAs($this->manager)->post(
        route('admin.orders.shipments.store', $this->order->public_id),
        [
            'provider' => 'manual',
            'delivery_charge' => '80.00',
            'packages' => [['weight' => '1.5']],
        ],
    );

    $shipment = Shipment::query()->sole();

    $this->actingAs($this->manager)->post(
        route('admin.shipments.status.advance', $shipment->public_id),
        ['status' => 'pickup_requested'],
    )->assertRedirect();

    expect($shipment->refresh()->status->value)->toBe('pickup_requested');
});
