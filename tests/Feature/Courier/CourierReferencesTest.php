<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Courier\Models\Shipment;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/*
 * Recording a courier's own references by hand, for a parcel Banij holds
 * without them -- a Steadfast booking confirmed by invoice lookup alone, or a
 * manual shipment raised before its tracking number was known.
 */

require_once __DIR__.'/SteadfastFixtures.php';

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->manager = testPlatformStaff(PlatformRole::CourierManager);
    steadfastFixtureEnable();
    Http::fake(['portal.steadfast.com.bd/*' => Http::response([], 500)]);

    // As invoice-only reconciliation leaves it: booked, but neither reference known.
    $this->shipment = steadfastFixtureShipment(attributes: ['external_id' => null, 'tracking_number' => null, 'needs_review' => true]);
});

function courierReferencesPost(Shipment $shipment, array $data): TestResponse
{
    return test()->actingAs(test()->manager)->post(route('admin.shipments.courier-references.store', $shipment->public_id), $data);
}

it('records both missing references with the reason, and never calls the courier', function () {
    courierReferencesPost($this->shipment, [
        'consignment_id' => '1424107',
        'tracking_code' => '15BAEB8A',
        'reason' => 'Read from the Steadfast merchant panel',
    ])->assertSessionHasNoErrors();

    $shipment = $this->shipment->refresh();

    expect($shipment->external_id)->toBe('1424107')
        ->and($shipment->tracking_number)->toBe('15BAEB8A');

    $audit = AuditLog::query()->where('action', 'shipment.courier_references_recorded')->sole();
    expect($audit->reason)->toBe('Read from the Steadfast merchant panel')
        ->and($audit->actor_id)->toBe($this->manager->id);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'steadfast'));
});

it('demands a reason, even though admin routes otherwise default one', function () {
    courierReferencesPost($this->shipment, ['tracking_code' => '15BAEB8A'])
        ->assertInvalid(['reason']);

    expect($this->shipment->refresh()->tracking_number)->toBeNull();
});

it('never overwrites a reference already recorded', function () {
    $this->shipment->forceFill(['tracking_number' => 'ORIGINAL'])->save();

    courierReferencesPost($this->shipment, ['tracking_code' => 'REPLACED', 'reason' => 'typo'])
        ->assertInvalid(['references']);

    expect($this->shipment->refresh()->tracking_number)->toBe('ORIGINAL');
});

it('refuses a consignment id another shipment already carries', function () {
    steadfastFixtureShipment(attributes: ['external_id' => '9999999']);

    courierReferencesPost($this->shipment, ['consignment_id' => '9999999', 'reason' => 'panel'])
        ->assertInvalid(['references']);

    expect($this->shipment->refresh()->external_id)->toBeNull();
});

it('refuses an empty submission', function () {
    courierReferencesPost($this->shipment, ['consignment_id' => '', 'tracking_code' => ' ', 'reason' => 'panel'])
        ->assertInvalid(['references']);
});

it('refuses a staff member who cannot manage shipments', function () {
    $this->actingAs(testPlatformStaff(PlatformRole::OrderManager))
        ->post(route('admin.shipments.courier-references.store', $this->shipment->public_id), [
            'tracking_code' => '15BAEB8A',
            'reason' => 'panel',
        ])->assertForbidden();

    expect($this->shipment->refresh()->tracking_number)->toBeNull();
});

it('offers only the blanks on the shipment screen', function () {
    $this->shipment->forceFill(['tracking_number' => '15BAEB8A'])->save();

    $this->actingAs($this->manager)
        ->get(route('admin.shipments.show', $this->shipment->public_id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('shipment.missing_references', ['consignment_id']));
});
