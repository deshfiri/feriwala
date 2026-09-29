<?php

use App\Domain\Access\Enums\PlatformRole;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Listing and inspecting the twenty-one PlatformRole cases (commit-order
 * item 6). Read-only: there is no route here that writes anything.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('redirects a guest to the login page', function () {
    $this->get(route('admin.roles.index'))->assertRedirect(route('login'));
    $this->get(route('admin.roles.show', 'sms_manager'))->assertRedirect(route('login'));
});

it('is closed to staff without access.view', function () {
    $staff = testPlatformStaff(PlatformRole::SmsManager);

    $this->actingAs($staff)
        ->get(route('admin.roles.index'))
        ->assertForbidden();
});

it('is closed to a business user', function () {
    $account = testBusinessAccount();

    $this->actingAs($account->owner)
        ->get(route('admin.roles.index'))
        ->assertForbidden();
});

it('lists all twenty-one roles for staff holding access.view', function () {
    $staff = testPlatformStaff(PlatformRole::SystemAdministrator);

    $this->actingAs($staff)
        ->get(route('admin.roles.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/roles/index')
            ->has('roles', 21));
});

it('counts an assigned role\'s holders and never marks it protected', function () {
    $staff = testPlatformStaff(PlatformRole::SystemAdministrator);
    testPlatformStaff(PlatformRole::SmsManager);
    testPlatformStaff(PlatformRole::SmsManager);

    $this->actingAs($staff)
        ->get(route('admin.roles.index'))
        ->assertOk()
        ->assertInertia(function (Assert $page) {
            $roles = collect($page->toArray()['props']['roles']);
            $sms = $roles->firstWhere('key', 'sms_manager');

            expect($sms['is_protected'])->toBeFalse()
                ->and($sms['holder_count'])->toBe(2)
                ->and($sms['permission_count'])->toBeGreaterThan(0);
        });
});

it('shows a role\'s permission grants grouped by module, and who holds it', function () {
    $staff = testPlatformStaff(PlatformRole::SystemAdministrator);
    $smsManager = testPlatformStaff(PlatformRole::SmsManager);

    $this->actingAs($staff)
        ->get(route('admin.roles.show', 'sms_manager'))
        ->assertOk()
        ->assertInertia(function (Assert $page) use ($smsManager) {
            $page->component('admin/roles/show')
                ->where('role.key', 'sms_manager')
                ->where('role.is_protected', false)
                ->has('holders', 1)
                ->where('holders.0.public_id', $smsManager->public_id)
                ->has('permissionGroups');

            $groups = collect($page->toArray()['props']['permissionGroups']);

            expect($groups->pluck('module'))->toContain('SMS');
        });
});

it('marks Super Admin protected, and does not list it as individual permission rows', function () {
    $staff = testPlatformStaff(PlatformRole::SystemAdministrator);

    $this->actingAs($staff)
        ->get(route('admin.roles.show', 'super_admin'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/roles/show')
            ->where('role.is_protected', true)
            ->where('permissionGroups', []));
});

it('tells a dotted module value apart from the plain one it prefixes', function () {
    /*
     * Regression: Module::SupplierKyc's own value ("supplier.kyc") contains
     * a dot, so "supplier.kyc.view" was briefly parsed as module "supplier"
     * action "kyc.view" -- a real module, so the mistake produced no error
     * for a modern PHP array key, but PermissionAction::from('kyc.view')
     * throws a ValueError, and Supplier Manager holds exactly this
     * permission.
     */
    $staff = testPlatformStaff(PlatformRole::SystemAdministrator);

    $this->actingAs($staff)
        ->get(route('admin.roles.show', 'supplier_manager'))
        ->assertOk()
        ->assertInertia(function (Assert $page) {
            $groups = collect($page->toArray()['props']['permissionGroups']);

            expect($groups->pluck('module'))
                ->toContain('Supplier KYC')
                ->toContain('Suppliers');

            $supplierKyc = $groups->firstWhere('module', 'Supplier KYC');

            expect($supplierKyc['actions'])->toContain('View')
                ->and($supplierKyc['actions'])->toContain('Review');
        });
});

it('404s an unknown role key rather than guessing', function () {
    $staff = testPlatformStaff(PlatformRole::SystemAdministrator);

    $this->actingAs($staff)
        ->get(route('admin.roles.show', 'not-a-real-role'))
        ->assertNotFound();
});
