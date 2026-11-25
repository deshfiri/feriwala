<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Inventory\Models\Warehouse;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
 * A warehouse's courier contact and return address: what every shipping label
 * from it prints as the sender. Never approved for labels while incomplete.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->manager = testPlatformStaff(PlatformRole::InventoryManager);
    $this->warehouse = Warehouse::create(['code' => 'TJG-1', 'name' => 'Tejgaon', 'is_active' => true]);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function warehouseCourierPayload(array $overrides = []): array
{
    return [
        'name' => 'Tejgaon',
        'is_active' => true,
        'courier_contact_name' => 'Karim Dispatch',
        'courier_phone' => '01800000001',
        'return_address_line' => '12 Tejgaon Industrial Area',
        'return_area' => 'Tejgaon',
        'return_district' => 'Dhaka',
        'return_postcode' => '1208',
        'usable_for_courier_labels' => true,
        ...$overrides,
    ];
}

it('records the courier details and approves the warehouse for labels, audited', function () {
    $this->actingAs($this->manager)
        ->patch(route('admin.inventory.warehouses.update', $this->warehouse->public_id), warehouseCourierPayload())
        ->assertSessionHasNoErrors();

    $warehouse = $this->warehouse->refresh();

    expect($warehouse->isUsableForCourierLabels())->toBeTrue()
        ->and($warehouse->courierReturnAddress())->toBe(['line' => '12 Tejgaon Industrial Area', 'area' => 'Tejgaon', 'district' => 'Dhaka', 'postcode' => '1208'])
        ->and(AuditLog::query()->where('action', 'inventory.warehouse_updated')->latest('id')->first()->after['courier_phone'])->toBe('01800000001');
});

it('refuses approval while a required part of the return details is blank', function (string $field) {
    $this->actingAs($this->manager)
        ->patch(route('admin.inventory.warehouses.update', $this->warehouse->public_id), warehouseCourierPayload([$field => '']))
        ->assertInvalid([$field]);

    expect($this->warehouse->refresh()->usable_for_courier_labels)->toBeFalse();
})->with(['courier_contact_name', 'courier_phone', 'return_address_line', 'return_district']);

it('keeps incomplete details without approving them', function () {
    $this->actingAs($this->manager)
        ->patch(route('admin.inventory.warehouses.update', $this->warehouse->public_id), warehouseCourierPayload([
            'return_district' => '',
            'usable_for_courier_labels' => false,
        ]))
        ->assertSessionHasNoErrors();

    expect($this->warehouse->refresh())
        ->courier_phone->toBe('01800000001')
        ->isUsableForCourierLabels()->toBeFalse();
});

it('is not usable for labels once switched off, even with complete details', function () {
    $this->warehouse->forceFill([...warehouseCourierPayload(), 'is_active' => false])->save();

    expect($this->warehouse->refresh()->isUsableForCourierLabels())->toBeFalse();
});
