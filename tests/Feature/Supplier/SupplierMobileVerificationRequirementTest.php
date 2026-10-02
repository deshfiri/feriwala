<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Actions\ConfigureMobileVerificationRequirement;
use App\Domain\Supplier\Actions\AdvanceSupplierPastVerification;
use App\Domain\Supplier\Enums\SupplierStatus;
use App\Domain\Supplier\Models\Supplier;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
 * The administrator's mobile-verification switch governs Suppliers as well as
 * Clients/Partners: off means a Supplier is held only on their email.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function supplierRequirementSwitchOff(): void
{
    app(ConfigureMobileVerificationRequirement::class)
        ->handle(testPlatformStaff(PlatformRole::Admin), false);
}

it('keeps a supplier on verification until mobile is confirmed while the requirement is on', function () {
    $supplier = Supplier::factory()->verificationPending()->create(['email_verified_at' => now()]);

    app(AdvanceSupplierPastVerification::class)->handle($supplier);

    expect($supplier->fresh()->status)->toBe(SupplierStatus::VerificationPending);
});

it('moves a supplier with a verified email on to KYC once the requirement is off', function () {
    supplierRequirementSwitchOff();
    $supplier = Supplier::factory()->verificationPending()->create(['email_verified_at' => now()]);

    app(AdvanceSupplierPastVerification::class)->handle($supplier);

    expect($supplier->fresh()->status)->toBe(SupplierStatus::KycPending);
});

it('still requires the email while the requirement is off', function () {
    supplierRequirementSwitchOff();
    $supplier = Supplier::factory()->verificationPending()->create();

    app(AdvanceSupplierPastVerification::class)->handle($supplier);

    expect($supplier->fresh()->status)->toBe(SupplierStatus::VerificationPending);
});

it('sends a waiting supplier on to the dashboard from the verification notice', function () {
    supplierRequirementSwitchOff();
    $supplier = Supplier::factory()->verificationPending()->create(['email_verified_at' => now()]);

    $this->actingAs($supplier, 'supplier')
        ->get(route('supplier.verification.notice'))
        ->assertRedirect(route('supplier.dashboard'));

    expect($supplier->fresh()->status)->toBe(SupplierStatus::KycPending);
});

it('sends no code and shows no mobile screen while the requirement is off', function () {
    supplierRequirementSwitchOff();
    $supplier = Supplier::factory()->verificationPending()->create(['email_verified_at' => now()]);

    $this->actingAs($supplier, 'supplier')
        ->get(route('supplier.verification.mobile'))
        ->assertRedirect(route('supplier.verification.notice'));

    $this->actingAs($supplier, 'supplier')
        ->post(route('supplier.verification.mobile.send'))
        ->assertRedirect(route('supplier.verification.notice'));
});
