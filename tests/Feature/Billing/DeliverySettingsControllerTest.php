<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Billing\Models\DeliveryChargeRule;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
 * The admin delivery-charge settings screen, gated on its own
 * `delivery_settings.*` permissions rather than any courier or order
 * ability (beta-critical batch, Commit 2).
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('lets a CourierManager view, save settings and manage rules', function () {
    $staff = testPlatformStaff(PlatformRole::CourierManager);

    $this->actingAs($staff)
        ->get(route('admin.delivery-settings.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/delivery-settings/index')
            ->where('can.edit', true)
            ->where('can.manage_settings', true));

    $this->actingAs($staff)->patch(route('admin.delivery-settings.update'), [
        'volumetric_divisor' => 5000,
        'use_greater_of_actual_and_volumetric' => true,
        'additional_per_kg_charge' => '10.00',
        'per_box_charge' => '15.00',
        'fragile_handling_charge' => '20.00',
        'minimum_charge' => '50.00',
        'delivery_success_fee_percent' => '1.00',
    ])->assertSessionHasNoErrors();

    $this->actingAs($staff)->post(route('admin.delivery-settings.rules.store'), [
        'weight_from_grams' => 0,
        'base_charge' => '60.00',
        'effective_from' => now()->toDateTimeString(),
    ])->assertSessionHasNoErrors();

    expect(DeliveryChargeRule::query()->count())->toBe(1);
});

it('refuses a staff member without any delivery_settings permission', function () {
    $staff = testPlatformStaff(PlatformRole::OrderManager);

    $this->actingAs($staff)
        ->get(route('admin.delivery-settings.index'))
        ->assertForbidden();
});

it('shows view-only screen state without edit abilities for a viewer without the edit permission', function () {
    // ProductManager has no delivery_settings grant at all -- confirms the
    // permission is genuinely its own, not inherited from an unrelated role.
    $staff = testPlatformStaff(PlatformRole::ProductManager);

    $this->actingAs($staff)
        ->get(route('admin.delivery-settings.index'))
        ->assertForbidden();

    $this->actingAs($staff)->patch(route('admin.delivery-settings.update'), [
        'volumetric_divisor' => 5000,
        'use_greater_of_actual_and_volumetric' => true,
        'additional_per_kg_charge' => '10.00',
        'per_box_charge' => '15.00',
        'fragile_handling_charge' => '20.00',
        'minimum_charge' => '50.00',
        'delivery_success_fee_percent' => '1.00',
    ])->assertForbidden();
});
