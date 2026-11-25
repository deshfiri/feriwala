<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Courier\Actions\BookSteadfastShipment;
use App\Domain\Courier\Exceptions\CourierBookingRefused;
use App\Domain\Courier\Models\CourierBookingAttempt;
use App\Domain\Courier\Models\Shipment;
use App\Domain\Courier\Queries\StoreOrderCourierPanel;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Order\Models\OrderItemAllocation;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
 * One parcel leaves from one Banij warehouse. Under the single-shipment model an
 * order whose stock is spread over several warehouses, or held where no record
 * names one, is held for staff before any courier exists -- every check runs
 * before a single request could reach Steadfast.
 */

require_once __DIR__.'/SteadfastFixtures.php';

const SINGLE_WAREHOUSE_MULTIPLE = 'This order requires fulfilment from multiple warehouses and cannot use a single parcel.';

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->staff = testPlatformStaff(PlatformRole::CourierManager);
    steadfastFixtureEnable();

    // A dispatched Store Order with one line in one warehouse.
    $this->order = steadfastFixtureOrder();
});

function singleWarehouseSecondWarehouse(): Warehouse
{
    return Warehouse::create(['code' => 'CTG-'.Str::upper(Str::random(5)), 'name' => 'Banij Chattogram Hub', 'is_active' => true]);
}

it('books a parcel whose every line is held in one warehouse', function () {
    Http::fake(['portal.steadfast.com.bd/*' => Http::response([
        'status' => 200,
        'consignment' => ['consignment_id' => 9001, 'invoice' => $this->order->reference, 'tracking_code' => 'TRK1', 'status' => 'in_review'],
    ])]);

    app(BookSteadfastShipment::class)->handle($this->order, $this->staff);

    expect(Shipment::query()->where('order_id', $this->order->id)->count())->toBe(1);
});

it('never calls the courier for an order spread over two warehouses', function () {
    steadfastFixtureWarehouseLine($this->order, singleWarehouseSecondWarehouse());
    Http::fake();

    expect(fn () => app(BookSteadfastShipment::class)->handle($this->order, $this->staff))
        ->toThrow(CourierBookingRefused::class, SINGLE_WAREHOUSE_MULTIPLE);

    Http::assertNothingSent();
    expect(CourierBookingAttempt::query()->count())->toBe(0)
        ->and(Shipment::query()->count())->toBe(0);
});

it('never calls the courier when no warehouse can be proven', function () {
    // The line's allocation released: nothing names where its units are.
    OrderItemAllocation::query()->where('order_id', $this->order->id)
        ->update(['status' => 'released', 'released_at' => now()]);
    Http::fake();

    expect(fn () => app(BookSteadfastShipment::class)->handle($this->order->refresh(), $this->staff))
        ->toThrow(CourierBookingRefused::class, 'Fulfilment warehouse could not be determined');

    Http::assertNothingSent();
    expect(CourierBookingAttempt::query()->count())->toBe(0);
});

it('refuses a manual shipment for an order spread over two warehouses, and holds the order for staff', function () {
    steadfastFixtureWarehouseLine($this->order, singleWarehouseSecondWarehouse());

    $this->actingAs($this->staff)
        ->post(route('admin.orders.shipments.store', $this->order->public_id), [
            'provider' => 'manual', 'delivery_charge' => '80.00', 'cod_amount' => '1250.00', 'packages' => [['weight' => '1.0']],
        ])
        ->assertInvalid(['provider' => SINGLE_WAREHOUSE_MULTIPLE]);

    expect(Shipment::query()->count())->toBe(0);

    $panel = app(StoreOrderCourierPanel::class)->forStaff($this->order, $this->staff);
    expect($panel['warehouse_problem'])->toBe(SINGLE_WAREHOUSE_MULTIPLE)
        ->and($panel['can']['book'])->toBeFalse();
});
